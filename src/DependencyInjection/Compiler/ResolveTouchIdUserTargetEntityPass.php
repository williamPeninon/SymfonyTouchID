<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use WpConsulting\TouchIdBundle\Doctrine\ResolveTouchIdUserListener;

/**
 * Ensures the dedicated resolve listener is registered before Doctrine wires event managers.
 */
final class ResolveTouchIdUserTargetEntityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('wp_consulting_touch_id.user_class')) {
            return;
        }

        $userClass = $container->getParameter('wp_consulting_touch_id.user_class');
        if (!\is_string($userClass) || $userClass === '' || !class_exists($userClass)) {
            return;
        }

        if ($container->hasDefinition('touch_id.doctrine.resolve_target_user')) {
            return;
        }

        // Fallback if Extension::load did not register it (should be rare).
        $definition = new Definition(ResolveTouchIdUserListener::class);
        $definition->setArgument('$userClass', $userClass);
        $definition->addTag('doctrine.event_listener', [
            'event' => Events::loadClassMetadata,
            'priority' => 256,
        ]);
        $definition->addTag('doctrine.event_listener', [
            'event' => Events::onClassMetadataNotFound,
            'priority' => 256,
        ]);
        $container->setDefinition('touch_id.doctrine.resolve_target_user', $definition);
    }
}
