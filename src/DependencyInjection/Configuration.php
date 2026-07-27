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
                    ->setDeprecated('wpconsulting/touch-id-bundle', '2.0', 'The "user_repository" option is ignored; users are loaded via EntityManager and user_class.')
                    ->info('Deprecated — ignored. Kept so existing YAML still loads.')
                ->end()
                ->scalarNode('user_identifier_field')
                    ->defaultValue('email')
                    ->cannotBeEmpty()
                    ->info('Doctrine field used to look up the user at WebAuthn login (usually email or username)')
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
                ->scalarNode('email_input_selector')
                    ->defaultValue('#username, input[name="_username"], input[name="email"], input[type="email"]')
                    ->cannotBeEmpty()
                    ->info('CSS selector(s) used by the login Stimulus controller to read the email before WebAuthn')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
