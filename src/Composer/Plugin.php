<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Composer;

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
 * Publishes Flex-equivalent skeleton files and prints setup instructions
 * even when the custom Flex endpoint is not configured (auto-generated recipe).
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'wpconsulting/touch-id-bundle';

    private static bool $packageTouched = false;

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
            PackageEvents::POST_PACKAGE_INSTALL => ['onPackageChange', 0],
            PackageEvents::POST_PACKAGE_UPDATE => ['onPackageChange', 0],
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
            self::$packageTouched = true;
        }
    }

    public function onFinish(Event $event): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $package = $localRepo->findPackage(self::PACKAGE_NAME, '*');
        if (!$package) {
            return;
        }

        $installPath = $composer->getInstallationManager()->getInstallPath($package);
        if (!$installPath || !is_dir($installPath)) {
            return;
        }

        $projectDir = \dirname($composer->getConfig()->get('vendor-dir'));
        $skeletonDir = $installPath.'/skeleton';
        if (!is_dir($skeletonDir)) {
            return;
        }

        $created = [];
        foreach ([
            'config/packages/wp_consulting_touch_id.yaml',
            'config/routes/touch_id.yaml',
        ] as $relative) {
            $target = $projectDir.'/'.$relative;
            $source = $skeletonDir.'/'.$relative;
            if (!is_file($source) || is_file($target)) {
                continue;
            }
            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                $io->writeError(sprintf('  <error>Could not create directory %s</error>', $dir));
                continue;
            }
            if (!copy($source, $target)) {
                $io->writeError(sprintf('  <error>Could not create %s</error>', $relative));
                continue;
            }
            $created[] = $relative;
        }

        $bundleRegistered = $this->ensureBundleRegistered($projectDir, $io);

        if ($created !== []) {
            $io->write('');
            $io->write('  <info>wpconsulting/touch-id-bundle</info>: skeleton files created:');
            foreach ($created as $file) {
                $io->write(sprintf('    <fg=green>Created</> %s', $file));
            }
        }

        if (self::$packageTouched || $created !== [] || $bundleRegistered) {
            $this->writeInstructions($io);
        }

        // Avoid double-printing if both POST_INSTALL and POST_UPDATE fire.
        self::$packageTouched = false;
    }

    private function ensureBundleRegistered(string $projectDir, IOInterface $io): bool
    {
        $bundlesFile = $projectDir.'/config/bundles.php';
        if (!is_file($bundlesFile)) {
            return false;
        }

        $contents = file_get_contents($bundlesFile);
        if ($contents === false || str_contains($contents, 'WpConsulting\\TouchIdBundle\\TouchIdBundle')) {
            return false;
        }

        $line = "    WpConsulting\\TouchIdBundle\\TouchIdBundle::class => ['all' => true],\n";
        $updated = preg_replace('/\];\s*$/', $line.'];', $contents, 1);
        if (!\is_string($updated) || $updated === $contents) {
            return false;
        }

        file_put_contents($bundlesFile, $updated);
        $io->write('    <fg=green>Registered</> TouchIdBundle in config/bundles.php');

        return true;
    }

    private function writeInstructions(IOInterface $io): void
    {
        $io->write('');
        $io->write('  <bg=blue;fg=white> wpconsulting/touch-id-bundle </>');
        $io->write('');
        $io->write('  * Edit <comment>config/packages/wp_consulting_touch_id.yaml</> (user_class).');
        $io->write('  * Implement <comment>TouchIdUserInterface</> on your User entity.');
        $io->write('  * Add PUBLIC_ACCESS for <comment>^/webauthn/login</> in security.yaml.');
        $io->write('  * Enable Stimulus controllers in <comment>assets/controllers.json</> (see README).');
        $io->write('  * Create the table: <comment>php bin/console touch-id:install</>');
        $io->write('  * Twig: <comment>touch_id_credentials(app.user)</> / global <comment>touch_id_manager</>.');
        $io->write('');
    }
}
