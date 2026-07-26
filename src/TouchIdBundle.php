<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use WpConsulting\TouchIdBundle\DependencyInjection\TouchIdExtension;

final class TouchIdBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new TouchIdExtension();
    }
}
