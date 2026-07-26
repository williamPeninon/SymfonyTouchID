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
use WpConsulting\TouchIdBundle\Contract\TouchIdUserRepositoryInterface;
use WpConsulting\TouchIdBundle\Controller\TouchIdController;
use WpConsulting\TouchIdBundle\Service\TouchIdManager;
use WpConsulting\TouchIdBundle\Twig\TouchIdTwigExtension;

final class TouchIdExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (empty($config['user_class']) || empty($config['user_repository'])) {
            throw new \InvalidArgumentException('You must configure "wp_consulting_touch_id.user_class" and "wp_consulting_touch_id.user_repository".');
        }

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        $container->getDefinition(TouchIdManager::class)
            ->setPublic(true)
            ->setArgument('$rpName', $config['rp_name'])
            ->setArgument('$defaultCredentialName', $config['default_credential_name']);

        $container->setAlias('touch_id.manager', TouchIdManager::class)->setPublic(true);

        $controller = $container->getDefinition(TouchIdController::class);
        $controller
            ->setArgument('$userRepository', new Reference($config['user_repository']))
            ->setArgument('$loginAuthenticator', $config['login_authenticator'])
            ->setArgument('$defaultRedirectRoute', $config['default_redirect_route'])
            ->setArgument('$translationDomain', $config['translation_domain'])
            ->setArgument('$translationPrefix', $config['translation_prefix']);

        if (!empty($config['success_handler'])) {
            $controller->setArgument('$successHandler', new Reference($config['success_handler']));
        } else {
            $controller->setArgument('$successHandler', null);
        }

        $container->setAlias(TouchIdUserRepositoryInterface::class, $config['user_repository'])
            ->setPublic(false);

        $container->setParameter('wp_consulting_touch_id.user_class', $config['user_class']);

        if ($container->hasDefinition(TouchIdTwigExtension::class)) {
            $container->getDefinition(TouchIdTwigExtension::class)
                ->setArgument('$defaultRedirectRoute', $config['default_redirect_route'])
                ->setArgument('$emailInputSelector', $config['email_input_selector'])
                ->setArgument('$translationDomain', $config['translation_domain']);
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (!empty($config['user_class'])) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'resolve_target_entities' => [
                        TouchIdUserInterface::class => $config['user_class'],
                    ],
                    'mappings' => [
                        'TouchIdBundle' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => \dirname(__DIR__).'/Entity',
                            'prefix' => 'WpConsulting\TouchIdBundle\Entity',
                            'alias' => 'TouchIdBundle',
                        ],
                    ],
                ],
            ]);
        }

        $bundleRoot = \dirname(__DIR__, 2);

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
