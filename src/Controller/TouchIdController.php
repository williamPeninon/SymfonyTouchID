<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserRepositoryInterface;
use WpConsulting\TouchIdBundle\Repository\WebAuthnCredentialRepository;
use WpConsulting\TouchIdBundle\Service\TouchIdManager;

#[AsController]
final class TouchIdController
{
    public function __construct(
        private readonly TouchIdManager $touchIdManager,
        private readonly WebAuthnCredentialRepository $credentialRepository,
        private readonly TouchIdUserRepositoryInterface $userRepository,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $loginAuthenticator,
        private readonly string $defaultRedirectRoute,
        private readonly string $translationDomain,
        private readonly string $translationPrefix,
        private readonly ?AuthenticationSuccessHandlerInterface $successHandler = null,
    ) {}

    #[Route('/webauthn/register/options', name: 'webauthn_register_options', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $this->requireTouchIdUser();

        try {
            return new JsonResponse($this->touchIdManager->getRegistrationOptions($user, $request));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/webauthn/register/verify', name: 'webauthn_register_verify', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function registerVerify(Request $request): JsonResponse
    {
        $user = $this->requireTouchIdUser();
        $payload = $this->decodeJson($request);

        if ($payload === null) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('invalid')], 400);
        }

        $name = \is_string($payload->name ?? null) ? trim($payload->name) : null;
        if ($name === '') {
            $name = null;
        }

        try {
            $credential = $this->touchIdManager->registerCredential($user, $request, $payload, $name);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->trans('registered'),
            'credential' => [
                'id' => $credential->getId(),
                'name' => $credential->getName(),
                'createdAt' => $credential->getCreatedAt()->format('d/m/Y H:i'),
            ],
        ]);
    }

    #[Route('/webauthn/credentials/{id}', name: 'webauthn_credential_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function deleteCredential(int $id): JsonResponse
    {
        $user = $this->requireTouchIdUser();

        if (!$this->touchIdManager->deleteCredential($user, $id)) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('not_found')], 404);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->trans('deleted'),
        ]);
    }

    #[Route('/webauthn/login/options', name: 'webauthn_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        if ($this->security->getUser()) {
            return new JsonResponse(['success' => false, 'message' => 'already_authenticated'], 400);
        }

        $payload = $this->decodeJson($request);
        $user = null;
        $email = \is_string($payload?->email ?? null) ? trim((string) $payload->email) : '';

        if ($email !== '') {
            $user = $this->userRepository->findOneForTouchIdByEmail($email);
            if (!$user || $this->credentialRepository->findByUser($user) === []) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->trans('no_credential'),
                ], 404);
            }
        }

        try {
            return new JsonResponse($this->touchIdManager->getAuthenticationOptions($request, $user));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/webauthn/login/verify', name: 'webauthn_login_verify', methods: ['POST'])]
    public function loginVerify(Request $request): Response
    {
        if ($this->security->getUser()) {
            return new JsonResponse([
                'success' => true,
                'redirect' => $this->urlGenerator->generate($this->defaultRedirectRoute),
            ]);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('invalid')], 400);
        }

        try {
            $user = $this->touchIdManager->authenticate($request, $payload);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        $loginResponse = $this->security->login($user, $this->loginAuthenticator);

        if ($loginResponse instanceof Response && $loginResponse->headers->has('Location')) {
            return new JsonResponse([
                'success' => true,
                'redirect' => $loginResponse->headers->get('Location'),
            ]);
        }

        $token = $this->security->getToken();
        if ($token !== null && $this->successHandler !== null) {
            $response = $this->successHandler->onAuthenticationSuccess($request, $token);
            if ($response instanceof Response && $response->headers->has('Location')) {
                return new JsonResponse([
                    'success' => true,
                    'redirect' => $response->headers->get('Location'),
                ]);
            }
        }

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->urlGenerator->generate($this->defaultRedirectRoute),
        ]);
    }

    private function requireTouchIdUser(): TouchIdUserInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof TouchIdUserInterface) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($this->translationPrefix . $key, [], $this->translationDomain);
    }

    private function decodeJson(Request $request): ?object
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_object($data) ? $data : null;
    }
}
