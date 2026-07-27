<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\DependencyInjection;

use Doctrine\ORM\Events;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use WpConsulting\PasskeyBundle\Contract\PasskeyUserInterface;
use WpConsulting\PasskeyBundle\Controller\PasskeyController;
use WpConsulting\PasskeyBundle\Doctrine\ResolvePasskeyUserListener;
use WpConsulting\PasskeyBundle\Service\PasskeyManager;
use WpConsulting\PasskeyBundle\Twig\PasskeyTwigExtension;

final class PasskeyExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $userClass = $config['user_class'] ?? null;

        if (\is_string($userClass) && $userClass !== '') {
            $this->assertValidUserClass($userClass);
        }

        $configured = $this->isFullyConfigured($config);

        $container->setParameter('wp_consulting_passkey.configured', $configured);
        $container->setParameter('wp_consulting_passkey.user_class', $userClass);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        // Configure command must work before user_class / PasskeyUserInterface are ready.
        $loader->load('services_installer.yaml');

        // Register as soon as user_class is a real class (even before it implements the interface),
        // so doctrine:schema:validate / migrations:diff can resolve the ManyToOne FK.
        if (\is_string($userClass) && $userClass !== '' && class_exists($userClass)) {
            $this->registerResolvePasskeyUserListener($container, $userClass);
        }

        // Allow the host app to boot (asset-map:compile, cache:clear) before User is ready.
        if (!$configured) {
            return;
        }

        $loader->load('services.yaml');

        $container->getDefinition(PasskeyManager::class)
            ->setPublic(true)
            ->setArgument('$rpName', $config['rp_name'])
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$userIdentifierField', $config['user_identifier_field'])
            ->setArgument('$defaultCredentialName', $config['default_credential_name']);

        $container->setAlias('passkey.manager', PasskeyManager::class)->setPublic(true);

        $controller = $container->getDefinition(PasskeyController::class);
        $controller
            ->setArgument('$loginAuthenticator', $config['login_authenticator'])
            ->setArgument('$defaultRedirectRoute', $config['default_redirect_route'])
            ->setArgument('$translationDomain', $config['translation_domain'])
            ->setArgument('$translationPrefix', $config['translation_prefix']);

        if (!empty($config['success_handler'])) {
            $controller->setArgument('$successHandler', new Reference($config['success_handler']));
        } else {
            $controller->setArgument('$successHandler', null);
        }

        if ($container->hasDefinition(PasskeyTwigExtension::class)) {
            $container->getDefinition(PasskeyTwigExtension::class)
                ->setArgument('$defaultRedirectRoute', $config['default_redirect_route'])
                ->setArgument('$emailInputSelector', $config['email_input_selector'])
                ->setArgument('$translationDomain', $config['translation_domain']);
        }
    }

    private function registerResolvePasskeyUserListener(ContainerBuilder $container, string $userClass): void
    {
        if ($container->hasDefinition('passkey.doctrine.resolve_target_user')) {
            return;
        }

        $definition = new Definition(ResolvePasskeyUserListener::class);
        $definition->setArgument('$userClass', $userClass);
        $definition->setPublic(false);
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

    private function assertValidUserClass(string $userClass): void
    {
        if (class_exists($userClass)) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'wp_consulting_passkey.user_class "%s" is not an existing PHP class. '
            .'Use the full entity FQCN (e.g. App\\Iam\\Auth\\Entity\\User), not a namespace like App\\Iam\\Auth\\Entity.',
            $userClass,
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isFullyConfigured(array $config): bool
    {
        $userClass = $config['user_class'] ?? null;

        return \is_string($userClass)
            && $userClass !== ''
            && class_exists($userClass)
            && is_a($userClass, PasskeyUserInterface::class, true);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);
        $bundleRoot = \dirname(__DIR__, 2);

        // Also feed DoctrineBundle's built-in resolver when the FQCN exists.
        if (!empty($config['user_class']) && \is_string($config['user_class']) && class_exists($config['user_class'])) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'resolve_target_entities' => [
                        PasskeyUserInterface::class => $config['user_class'],
                    ],
                ],
            ]);
        }

        $frameworkPrepend = [
            'translator' => [
                'paths' => [
                    $bundleRoot.'/translations',
                ],
            ],
        ];

        if (interface_exists(AssetMapperInterface::class)) {
            $frameworkPrepend['asset_mapper'] = [
                'paths' => [
                    $bundleRoot.'/assets' => '@wpconsulting/passkey-bundle',
                ],
            ];
        }

        $container->prependExtensionConfig('framework', $frameworkPrepend);

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $bundleRoot.'/templates' => 'Passkey',
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'wp_consulting_passkey';
    }
}
