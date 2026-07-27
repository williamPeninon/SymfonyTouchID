<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use WpConsulting\PasskeyBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertNull($config['user_class']);
        self::assertSame('email', $config['user_identifier_field']);
        self::assertSame('PasskeyBundle', $config['translation_domain']);
        self::assertSame('form_login', $config['login_authenticator']);
    }

    public function testUserClassCanBeSet(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
            'rp_name' => 'Demo',
        ]]);

        self::assertSame('App\\Entity\\User', $config['user_class']);
        self::assertSame('Demo', $config['rp_name']);
    }
}
