<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\DependencyInjection;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Controller\TouchIdController;
use WpConsulting\TouchIdBundle\Service\TouchIdManager;
use WpConsulting\TouchIdBundle\Twig\TouchIdTwigExtension;

final class TouchIdExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $configured = $this->isFullyConfigured($config);

        $container->setParameter('wp_consulting_touch_id.configured', $configured);
        $container->setParameter('wp_consulting_touch_id.user_class', $config['user_class'] ?? null);

        // Allow the host app to boot (asset-map:compile, cache:clear) before User exists.
        if (!$configured) {
            return;
        }

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        $container->getDefinition(TouchIdManager::class)
            ->setPublic(true)
            ->setArgument('$rpName', $config['rp_name'])
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$userIdentifierField', $config['user_identifier_field'])
            ->setArgument('$defaultCredentialName', $config['default_credential_name']);

        $container->setAlias('touch_id.manager', TouchIdManager::class)->setPublic(true);

        $controller = $container->getDefinition(TouchIdController::class);
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

        if ($container->hasDefinition(TouchIdTwigExtension::class)) {
            $container->getDefinition(TouchIdTwigExtension::class)
                ->setArgument('$defaultRedirectRoute', $config['default_redirect_route'])
                ->setArgument('$emailInputSelector', $config['email_input_selector'])
                ->setArgument('$translationDomain', $config['translation_domain']);
        }
    }

    /**
     * Skeleton placeholders (App\…) must not wire services until the class exists.
     *
     * @param array<string, mixed> $config
     */
    private function isFullyConfigured(array $config): bool
    {
        $userClass = $config['user_class'] ?? null;

        return \is_string($userClass)
            && $userClass !== ''
            && class_exists($userClass)
            && is_a($userClass, TouchIdUserInterface::class, true);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);
        $bundleRoot = \dirname(__DIR__, 2);

        $doctrineOrm = [
            'mappings' => [
                'TouchIdBundle' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => \dirname(__DIR__).'/Entity',
                    'prefix' => 'WpConsulting\TouchIdBundle\Entity',
                    'alias' => 'TouchIdBundle',
                ],
            ],
        ];

        if (!empty($config['user_class']) && class_exists($config['user_class'])) {
            $doctrineOrm['resolve_target_entities'] = [
                TouchIdUserInterface::class => $config['user_class'],
            ];
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => $doctrineOrm,
        ]);

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
                    $bundleRoot.'/assets' => '@wpconsulting/touch-id-bundle',
                ],
            ];
        }

        $container->prependExtensionConfig('framework', $frameworkPrepend);

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $bundleRoot.'/templates' => 'TouchId',
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'wp_consulting_touch_id';
    }
}
