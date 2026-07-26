<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use WpConsulting\TouchIdBundle\DependencyInjection\Compiler\ResolveTouchIdUserTargetEntityPass;
use WpConsulting\TouchIdBundle\DependencyInjection\TouchIdExtension;

final class TouchIdBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ResolveTouchIdUserTargetEntityPass());

        // Register entity mapping so doctrine:migrations:diff / schema tools see WebAuthnCredential
        // even when the host app only maps App\Entity.
        if (class_exists(DoctrineOrmMappingsPass::class)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['WpConsulting\TouchIdBundle\Entity'],
                [realpath(__DIR__.'/Entity') ?: (__DIR__.'/Entity')],
                [],
                false,
                ['TouchIdBundle' => 'WpConsulting\TouchIdBundle\Entity'],
            ));
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new TouchIdExtension();
    }
}
