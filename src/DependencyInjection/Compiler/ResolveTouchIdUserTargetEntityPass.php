<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

/**
 * Maps TouchIdUserInterface → configured user_class for Doctrine associations
 * (WebAuthnCredential::$user ManyToOne) and schema / migrations:diff.
 */
final class ResolveTouchIdUserTargetEntityPass implements CompilerPassInterface
{
    private const LISTENER_ID = 'doctrine.orm.listeners.resolve_target_entity';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('wp_consulting_touch_id.user_class')) {
            return;
        }

        $userClass = $container->getParameter('wp_consulting_touch_id.user_class');
        if (!\is_string($userClass) || $userClass === '' || !class_exists($userClass)) {
            return;
        }

        if (!$container->hasDefinition(self::LISTENER_ID) && !$container->hasAlias(self::LISTENER_ID)) {
            return;
        }

        $listener = $container->findDefinition(self::LISTENER_ID);
        $listener->addMethodCall('addResolveTargetEntity', [
            TouchIdUserInterface::class,
            $userClass,
            [],
        ]);

        // DoctrineExtension only tags this listener when resolve_target_entities is set in YAML.
        // When we register solely via this pass, we must enable the listener ourselves.
        if (!$this->hasEventTag($listener->getTag('doctrine.event_listener'), Events::loadClassMetadata)) {
            $listener->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
        }
        if (!$this->hasEventTag($listener->getTag('doctrine.event_listener'), Events::onClassMetadataNotFound)) {
            $listener->addTag('doctrine.event_listener', ['event' => Events::onClassMetadataNotFound]);
        }
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    private function hasEventTag(array $tags, string $event): bool
    {
        foreach ($tags as $tag) {
            if (($tag['event'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }
}
