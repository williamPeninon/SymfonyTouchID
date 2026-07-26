<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

/**
 * Maps TouchIdUserInterface → configured user_class for Doctrine associations.
 *
 * Must run before Doctrine's RegisterEventListenersAndSubscribersPass (priority 0)
 * so the listener tags are collected.
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

        if (!$this->alreadyResolves(TouchIdUserInterface::class, $listener->getMethodCalls())) {
            $listener->addMethodCall('addResolveTargetEntity', [
                TouchIdUserInterface::class,
                $userClass,
                [],
            ]);
        }

        // Required when resolve_target_entities was not set via doctrine YAML / prepend.
        if (!$this->hasEventTag($listener->getTag('doctrine.event_listener'), Events::loadClassMetadata)) {
            $listener->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
        }
        if (!$this->hasEventTag($listener->getTag('doctrine.event_listener'), Events::onClassMetadataNotFound)) {
            $listener->addTag('doctrine.event_listener', ['event' => Events::onClassMetadataNotFound]);
        }
    }

    /**
     * @param list<array{0: string, 1: array<mixed>}> $methodCalls
     */
    private function alreadyResolves(string $originalEntity, array $methodCalls): bool
    {
        foreach ($methodCalls as [$method, $args]) {
            if ($method === 'addResolveTargetEntity' && ($args[0] ?? null) === $originalEntity) {
                return true;
            }
        }

        return false;
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
