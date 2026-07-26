<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Entity\WebAuthnCredential;
use WpConsulting\TouchIdBundle\Service\TouchIdManager;

final class TouchIdTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly TouchIdManager $touchIdManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $defaultRedirectRoute,
        private readonly string $emailInputSelector,
        private readonly string $translationDomain,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('touch_id_credentials', $this->listCredentials(...)),
            new TwigFunction('touch_id_redirect_path', $this->redirectPath(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'touch_id_manager' => $this->touchIdManager,
            'touch_id_email_input_selector' => $this->emailInputSelector,
            'touch_id_redirect_path' => $this->redirectPath(),
            'touch_id_translation_domain' => $this->translationDomain,
        ];
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function listCredentials(?object $user): array
    {
        if (!$user instanceof TouchIdUserInterface) {
            return [];
        }

        return $this->touchIdManager->listCredentials($user);
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
