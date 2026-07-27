<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;
use WpConsulting\PasskeyBundle\Contract\PasskeyUserInterface;
use WpConsulting\PasskeyBundle\Entity\WebAuthnCredential;
use WpConsulting\PasskeyBundle\Service\PasskeyManager;

final class PasskeyTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly PasskeyManager $passkeyManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $defaultRedirectRoute,
        private readonly string $emailInputSelector,
        private readonly string $translationDomain,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('passkey_credentials', $this->listCredentials(...)),
            new TwigFunction('passkey_redirect_path', $this->redirectPath(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'passkey_manager' => $this->passkeyManager,
            'passkey_email_input_selector' => $this->emailInputSelector,
            'passkey_redirect_path' => $this->redirectPath(),
            'passkey_translation_domain' => $this->translationDomain,
        ];
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function listCredentials(?object $user): array
    {
        if (!$user instanceof PasskeyUserInterface) {
            return [];
        }

        return $this->passkeyManager->listCredentials($user);
    }

    public function redirectPath(?string $route = null): string
    {
        try {
            return $this->urlGenerator->generate($route ?? $this->defaultRedirectRoute);
        } catch (\Throwable) {
            return '/';
        }
    }
}
