<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Affiche les instructions post-install (passkey:configure), y compris sans recette Flex custom.
 *
 * @internal
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'wpconsulting/passkey-bundle';

    private static bool $touched = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageChange',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageChange',
            ScriptEvents::POST_INSTALL_CMD => ['onFinish', 10],
            ScriptEvents::POST_UPDATE_CMD => ['onFinish', 10],
        ];
    }

    public function onPackageChange(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            return;
        }

        if ($package->getName() === self::PACKAGE_NAME) {
            self::$touched = true;
        }
    }

    public function onFinish(Event $event): void
    {
        if (!self::$touched) {
            return;
        }
        self::$touched = false;

        $io = $event->getIO();
        $io->write('');
        $io->write('<bg=blue;fg=white> wpconsulting/passkey-bundle </>');
        $io->write('');
        $io->write('  <fg=yellow;options=bold>Commande suivante à exécuter :</>');
        $io->write('');
        $io->write('    <comment>php bin/console passkey:configure</>');
        $io->write('');
        $io->write('  <fg=yellow;options=bold>Cette commande va :</>');
        $io->write('    1. publier <comment>config/packages/wp_consulting_passkey.yaml</> et <comment>config/routes/passkey.yaml</>');
        $io->write('    2. publier <comment>config/packages/dev/framework.yaml</> (trusted_proxies ngrok — <fg=cyan>dev only</>)');
        $io->write('    3. enregistrer le bundle dans <comment>config/bundles.php</> si besoin');
        $io->write('    4. demander <comment>user_class</> + <comment>user_identifier_field</>');
        $io->write('    5. implémenter <comment>PasskeyUserInterface</> sur l’entité User');
        $io->write('    6. activer les contrôleurs Stimulus dans <comment>assets/controllers.json</>');
        $io->write('    7. ajouter <comment>PUBLIC_ACCESS</> pour <comment>^/webauthn/login</>');
        $io->write('    8. créer la table <comment>web_authn_credential</> (passkey:install / migrations)');
        $io->write('');
        $io->write('  Ensuite, inclure en Twig :');
        $io->write('    <comment>{% include \'@Passkey/passkey/_login_button.html.twig\' %}</>');
        $io->write('    <comment>{% include \'@Passkey/passkey/_manage.html.twig\' with { credentials: passkey_credentials(app.user) } %}</>');
        $io->write('');
    }
}
