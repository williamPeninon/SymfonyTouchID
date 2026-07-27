<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use WpConsulting\PasskeyBundle\Contract\PasskeyUserInterface;
use WpConsulting\PasskeyBundle\Entity\WebAuthnCredential;

/**
 * Rewrites WebAuthnCredential::$user target from PasskeyUserInterface to the configured user entity.
 */
final class ResolvePasskeyUserListener
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
            if (!$this->isPasskeyUserTarget($mapping->targetEntity)) {
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
        if (!$this->isPasskeyUserTarget($args->getClassName())) {
            return;
        }

        $args->setFoundMetadata(
            $args->getObjectManager()->getClassMetadata($this->userClass),
        );
    }

    private function isPasskeyUserTarget(string $className): bool
    {
        return ltrim($className, '\\') === PasskeyUserInterface::class;
    }
}
