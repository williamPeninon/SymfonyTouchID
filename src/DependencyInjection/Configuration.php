<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wp_consulting_touch_id');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('user_class')
                    ->defaultNull()
                    ->info('FQCN of your User entity implementing TouchIdUserInterface')
                ->end()
                ->scalarNode('user_repository')
                    ->defaultNull()
                    ->info('Service id of a class implementing TouchIdUserRepositoryInterface')
                ->end()
                ->scalarNode('rp_name')
                    ->defaultValue('Face ID / Touch ID')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('default_credential_name')
                    ->defaultValue('Face ID / Touch ID')
                ->end()
                ->scalarNode('login_authenticator')
                    ->defaultValue('form_login')
                    ->cannotBeEmpty()
                    ->info('Security authenticator name used by Security::login()')
                ->end()
                ->scalarNode('default_redirect_route')
                    ->defaultValue('homepage')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('success_handler')
                    ->defaultNull()
                    ->info('Optional AuthenticationSuccessHandlerInterface service id')
                ->end()
                ->scalarNode('translation_domain')
                    ->defaultValue('TouchIdBundle')
                ->end()
                ->scalarNode('translation_prefix')
                    ->defaultValue('')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
