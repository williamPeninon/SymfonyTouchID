<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Entity\WebAuthnCredential;
use WpConsulting\TouchIdBundle\Repository\WebAuthnCredentialRepository;

class TouchIdManager
{
    private const SESSION_CREATE_CHALLENGE = 'touch_id.create.challenge';
    private const SESSION_GET_CHALLENGE = 'touch_id.get.challenge';

    public function __construct(
        private readonly WebAuthnCredentialRepository $credentialRepository,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly string $rpName,
        private readonly string $defaultCredentialName = 'Touch ID',
    ) {}

    public function createFactory(Request $request): WebAuthn
    {
        return new WebAuthn($this->rpName, $this->resolveRpId($request), ['none'], true);
    }

    public function resolveRpId(Request $request): string
    {
        // Prefer the public host (ngrok / reverse proxy) over an internal rewritten Host.
        $host = $request->headers->get('X-Forwarded-Host') ?: $request->getHost();
        if (str_contains((string) $host, ',')) {
            $host = trim(explode(',', (string) $host, 2)[0]);
        }
        // Strip optional port (WebAuthn rpId must be a hostname only).
        if (str_contains((string) $host, ':') && !str_starts_with((string) $host, '[')) {
            $host = explode(':', (string) $host, 2)[0];
        }

        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return 'localhost';
        }

        // WebAuthn rejects IP addresses as rpId ("This is an invalid domain").
        if ($host === '127.0.0.1' || $host === '::1' || filter_var($host, \FILTER_VALIDATE_IP)) {
            throw new \RuntimeException(
                'Biometrics do not work with an IP address. Open the site via http://localhost'
                . ($request->getPort() && !\in_array($request->getPort(), [80, 443], true) ? ':' . $request->getPort() : '')
                . ' (not 127.0.0.1), or use an HTTPS public hostname (e.g. ngrok).'
            );
        }

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }

    public function getRegistrationOptions(TouchIdUserInterface $user, Request $request): object
    {
        $webAuthn = $this->createFactory($request);
        $excludeIds = [];

        foreach ($this->credentialRepository->findByUser($user) as $credential) {
            $excludeIds[] = $this->base64UrlDecode($credential->getCredentialId());
        }

        $args = $webAuthn->getCreateArgs(
            $this->userHandleFor($user),
            (string) $user->getEmail(),
            $user->getTouchIdDisplayName(),
            120,
            'preferred',
            'preferred', // Android Credential Manager is more reliable with preferred than required
            false, // platform authenticator (fingerprint / face on device)
            $excludeIds
        );

        $this->storeChallenge(self::SESSION_CREATE_CHALLENGE, $webAuthn->getChallenge());

        return $this->sanitizeCreateOptions($args);
    }

    public function registerCredential(TouchIdUserInterface $user, Request $request, object $payload, ?string $name = null): WebAuthnCredential
    {
        $webAuthn = $this->createFactory($request);
        $challenge = $this->consumeChallenge(self::SESSION_CREATE_CHALLENGE);

        try {
            $data = $webAuthn->processCreate(
                $this->decodeBinaryField($payload->clientDataJSON ?? null),
                $this->decodeBinaryField($payload->attestationObject ?? null),
                $challenge,
                false, // userVerification preferred on create — do not hard-fail if UV bit missing
                true,
                false
            );
        } catch (WebAuthnException $e) {
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }

        $credentialId = $this->base64UrlEncode($data->credentialId);
        if ($this->credentialRepository->findOneByCredentialId($credentialId)) {
            throw new \RuntimeException('This fingerprint is already registered.');
        }

        $credential = new WebAuthnCredential();
        $credential->setUser($user);
        $credential->setCredentialId($credentialId);
        $credential->setPublicKey($data->credentialPublicKey);
        $credential->setSignCount((int) ($data->signatureCounter ?? 0));
        $credential->setName($name ?: $this->defaultCredentialName);
        if (\is_string($data->AAGUID ?? null) && $data->AAGUID !== '') {
            $credential->setAaguid(bin2hex($data->AAGUID));
        }

        $this->em->persist($credential);
        $this->em->flush();

        return $credential;
    }

    public function getAuthenticationOptions(Request $request, ?TouchIdUserInterface $user = null): object
    {
        $webAuthn = $this->createFactory($request);
        $credentialIds = [];

        if ($user !== null) {
            foreach ($this->credentialRepository->findByUser($user) as $credential) {
                $credentialIds[] = $this->base64UrlDecode($credential->getCredentialId());
            }

            if ($credentialIds === []) {
                throw new \RuntimeException('No fingerprint registered for this account.');
            }
        }

        // Allow all transports: Android Credential Manager / Google Password Manager
        // often fail when restricted to "internal" only.
        $args = $webAuthn->getGetArgs(
            $credentialIds,
            120,
            true,
            true,
            true,
            true,
            true,
            'preferred'
        );

        $this->storeChallenge(self::SESSION_GET_CHALLENGE, $webAuthn->getChallenge());

        return $this->sanitizeGetOptions($args);
    }

    public function authenticate(Request $request, object $payload): TouchIdUserInterface
    {
        $webAuthn = $this->createFactory($request);
        $challenge = $this->consumeChallenge(self::SESSION_GET_CHALLENGE);

        $credentialId = $this->base64UrlEncode($this->decodeBinaryField($payload->id ?? null));
        $credential = $this->credentialRepository->findOneByCredentialId($credentialId);

        if (!$credential) {
            throw new \RuntimeException('Unknown fingerprint. Register Touch ID from your account first.');
        }

        try {
            $webAuthn->processGet(
                $this->decodeBinaryField($payload->clientDataJSON ?? null),
                $this->decodeBinaryField($payload->authenticatorData ?? null),
                $this->decodeBinaryField($payload->signature ?? null),
                $credential->getPublicKey(),
                $challenge,
                $credential->getSignCount() > 0 ? $credential->getSignCount() : null,
                false,
                true
            );
        } catch (WebAuthnException $e) {
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }

        $newCounter = $webAuthn->getSignatureCounter();
        if (\is_int($newCounter)) {
            $credential->setSignCount($newCounter);
        }
        $credential->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        $user = $credential->getUser();
        if (!$user instanceof TouchIdUserInterface) {
            throw new \RuntimeException('Account not found for this fingerprint.');
        }

        return $user;
    }

    public function deleteCredential(TouchIdUserInterface $user, int $credentialId): bool
    {
        $credential = $this->credentialRepository->find($credentialId);
        if (!$credential || !$this->sameUserId($credential->getUser()?->getId(), $user->getId())) {
            return false;
        }

        $this->em->remove($credential);
        $this->em->flush();

        return true;
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function listCredentials(TouchIdUserInterface $user): array
    {
        return $this->credentialRepository->findByUser($user);
    }

    private function userHandleFor(TouchIdUserInterface $user): string
    {
        return 'user:' . $this->stringifyId($user->getId());
    }

    private function sameUserId(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $this->stringifyId($a) === $this->stringifyId($b);
    }

    private function stringifyId(mixed $id): string
    {
        if (\is_string($id) || \is_int($id)) {
            return (string) $id;
        }

        if (\is_object($id) && ($id instanceof \Stringable || method_exists($id, '__toString'))) {
            return (string) $id;
        }

        throw new \InvalidArgumentException('User id must be stringable (int, string or Uuid).');
    }

    private function storeChallenge(string $key, ByteBuffer $challenge): void
    {
        $this->requestStack->getSession()->set($key, $challenge->getBinaryString());
    }

    private function consumeChallenge(string $key): string
    {
        $session = $this->requestStack->getSession();
        $challenge = $session->get($key);
        $session->remove($key);

        if (!\is_string($challenge) || $challenge === '') {
            throw new \RuntimeException('WebAuthn challenge expired. Please try again.');
        }

        return $challenge;
    }

    private function decodeBinaryField(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException('Invalid WebAuthn payload.');
        }

        $decoded = $this->base64UrlDecode($value);
        if ($decoded === '') {
            throw new \RuntimeException('Invalid WebAuthn payload.');
        }

        return $decoded;
    }

    public function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $data): string
    {
        $padding = 4 - (\strlen($data) % 4);
        if ($padding < 4) {
            $data .= str_repeat('=', $padding);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Android Credential Manager (Samsung / Chrome) rejects some non-standard options
     * produced by lbuchs/webauthn (e.g. extensions.exts) and prefers ES256 first.
     */
    private function sanitizeCreateOptions(object $args): object
    {
        if (!isset($args->publicKey) || !\is_object($args->publicKey)) {
            return $args;
        }

        unset($args->publicKey->extensions);

        $params = $args->publicKey->pubKeyCredParams ?? null;
        if (\is_array($params) && $params !== []) {
            $byAlg = [];
            foreach ($params as $param) {
                if (\is_object($param) && isset($param->alg)) {
                    $byAlg[(int) $param->alg] = $param;
                }
            }

            // ES256 first, then RS256. Skip EdDSA (-8): unreliable on some Android stacks.
            $ordered = [];
            foreach ([-7, -257] as $alg) {
                if (isset($byAlg[$alg])) {
                    $ordered[] = $byAlg[$alg];
                }
            }
            if ($ordered !== []) {
                $args->publicKey->pubKeyCredParams = $ordered;
            }
        }

        // Empty excludeCredentials can confuse some providers — omit when empty.
        if (isset($args->publicKey->excludeCredentials) && $args->publicKey->excludeCredentials === []) {
            unset($args->publicKey->excludeCredentials);
        }

        return $args;
    }

    private function sanitizeGetOptions(object $args): object
    {
        if (!isset($args->publicKey) || !\is_object($args->publicKey)) {
            return $args;
        }

        // Prefer omitting empty transports arrays on allowCredentials.
        if (isset($args->publicKey->allowCredentials) && \is_array($args->publicKey->allowCredentials)) {
            foreach ($args->publicKey->allowCredentials as $cred) {
                if (\is_object($cred) && isset($cred->transports) && $cred->transports === []) {
                    unset($cred->transports);
                }
            }
        }

        return $args;
    }
}
