<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Entity\WebAuthnCredential;

/**
 * Rewrites WebAuthnCredential::$user target from TouchIdUserInterface to the configured user entity.
 */
final class ResolveTouchIdUserListener
{
    public function __construct(
        private readonly string $userClass,
    ) {}

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== WebAuthnCredential::class) {
            return;
        }

        foreach ($metadata->associationMappings as $fieldName => $mapping) {
            if (!$this->isTouchIdUserTarget($mapping->targetEntity)) {
                continue;
            }

            $newMapping = $mapping->toArray();
            $newMapping['fieldName'] = $fieldName;
            $newMapping['targetEntity'] = $this->userClass;

            unset($metadata->associationMappings[$fieldName]);
            $metadata->mapManyToOne($newMapping);
        }
    }

    public function onClassMetadataNotFound(OnClassMetadataNotFoundEventArgs $args): void
    {
        if (!$this->isTouchIdUserTarget($args->getClassName())) {
            return;
        }

        $args->setFoundMetadata(
            $args->getObjectManager()->getClassMetadata($this->userClass),
        );
    }

    private function isTouchIdUserTarget(string $className): bool
    {
        return ltrim($className, '\\') === TouchIdUserInterface::class;
    }
}
