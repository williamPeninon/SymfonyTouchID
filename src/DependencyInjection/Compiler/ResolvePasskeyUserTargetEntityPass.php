<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use WpConsulting\PasskeyBundle\Doctrine\ResolvePasskeyUserListener;

/**
 * Ensures the dedicated resolve listener is registered before Doctrine wires event managers.
 */
final class ResolvePasskeyUserTargetEntityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('wp_consulting_passkey.user_class')) {
            return;
        }

        $userClass = $container->getParameter('wp_consulting_passkey.user_class');
        if (!\is_string($userClass) || $userClass === '' || !class_exists($userClass)) {
            return;
        }

        if ($container->hasDefinition('passkey.doctrine.resolve_target_user')) {
            return;
        }

        // Fallback if Extension::load did not register it (should be rare).
        $definition = new Definition(ResolvePasskeyUserListener::class);
        $definition->setArgument('$userClass', $userClass);
        $definition->addTag('doctrine.event_listener', [
            'event' => Events::loadClassMetadata,
            'priority' => 256,
        ]);
        $definition->addTag('doctrine.event_listener', [
            'event' => Events::onClassMetadataNotFound,
            'priority' => 256,
        ]);
        $container->setDefinition('passkey.doctrine.resolve_target_user', $definition);
    }
}
