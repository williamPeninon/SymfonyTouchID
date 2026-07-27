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
 * Publishes Flex-equivalent skeleton files, prompts for user_class,
 * and prints setup instructions when the custom Flex endpoint is missing.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'wpconsulting/touch-id-bundle';

    private const DEFAULT_USER_CLASS = 'App\\Entity\\User';

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

        $shouldSetup = self::$packageTouched || $created !== [] || $bundleRegistered;
        $userClassConfigured = false;
        if ($shouldSetup) {
            $userClassConfigured = $this->configureUserClass($projectDir, $io);
            $this->writeInstructions($io, $userClassConfigured);
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

    /**
     * Ask for the auth User entity FQCN and write it into wp_consulting_touch_id.yaml.
     *
     * @return bool true when user_class was written during this run
     */
    private function configureUserClass(string $projectDir, IOInterface $io): bool
    {
        $configFile = $projectDir.'/config/packages/wp_consulting_touch_id.yaml';
        if (!is_file($configFile)) {
            return false;
        }

        $contents = file_get_contents($configFile);
        if ($contents === false) {
            return false;
        }

        $existing = $this->readConfiguredUserClass($contents);
        if ($existing !== null) {
            $io->write(sprintf(
                '    <info>user_class</info> already set to <comment>%s</comment> — skipping prompt.',
                $existing
            ));

            return false;
        }

        $candidates = $this->discoverUserClassCandidates($projectDir);
        $default = $candidates[0] ?? self::DEFAULT_USER_CLASS;

        if (!$io->isInteractive()) {
            $io->write(sprintf(
                '    <comment>Non-interactive install:</comment> leave <comment>user_class: ~</comment> (set later to e.g. <comment>%s</comment>).',
                $default
            ));

            return false;
        }

        $io->write('');
        $io->write('  <bg=blue;fg=white> Touch ID — User entity </>');
        $io->write('  Which Doctrine entity is used for authentication?');
        $io->write('  Provide the <comment>FQCN</comment> (class name), not a namespace.');

        if ($candidates !== []) {
            $io->write('  Detected candidates:');
            foreach ($candidates as $i => $candidate) {
                $io->write(sprintf('    <comment>%d)</comment> %s', $i + 1, $candidate));
            }
        }

        $userClass = $io->askAndValidate(
            sprintf('  user_class [<comment>%s</comment>]: ', $default),
            static function (?string $answer) use ($default): string {
                $answer = trim((string) $answer);
                if ($answer === '') {
                    $answer = $default;
                }

                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $answer)) {
                    throw new \InvalidArgumentException('Invalid FQCN. Example: App\\Entity\\User');
                }

                return $answer;
            },
            null,
            $default
        );

        $identifierDefault = $this->guessIdentifierField($projectDir, $userClass) ?? 'email';
        $identifierField = $io->ask(
            sprintf('  user_identifier_field (Doctrine field for login lookup) [<comment>%s</comment>]: ', $identifierDefault),
            $identifierDefault
        );
        $identifierField = trim((string) $identifierField) ?: $identifierDefault;

        if (!$this->writeUserClassConfig($configFile, $contents, $userClass, $identifierField)) {
            $io->writeError('  <error>Could not write user_class into wp_consulting_touch_id.yaml</error>');

            return false;
        }

        $io->write(sprintf(
            '    <fg=green>Configured</> user_class=<comment>%s</comment>, user_identifier_field=<comment>%s</comment>',
            $userClass,
            $identifierField
        ));

        return true;
    }

    private function readConfiguredUserClass(string $yaml): ?string
    {
        if (!preg_match('/^\s*user_class:\s*(.+)\s*$/m', $yaml, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\"'");
        if ($value === '' || $value === '~' || strcasecmp($value, 'null') === 0) {
            return null;
        }

        return str_replace('\\\\', '\\', $value);
    }

    /**
     * @return list<string>
     */
    private function discoverUserClassCandidates(string $projectDir): array
    {
        $candidates = [];

        $fromSecurity = $this->detectUserClassFromSecurity($projectDir);
        if ($fromSecurity !== null) {
            $candidates[] = $fromSecurity;
        }

        foreach ($this->scanPhpForUserEntities($projectDir) as $class) {
            $candidates[] = $class;
        }

        $candidates[] = self::DEFAULT_USER_CLASS;

        $unique = [];
        foreach ($candidates as $class) {
            $unique[$class] = true;
        }

        return array_keys($unique);
    }

    private function detectUserClassFromSecurity(string $projectDir): ?string
    {
        $securityFile = $projectDir.'/config/packages/security.yaml';
        if (!is_file($securityFile)) {
            return null;
        }

        $contents = file_get_contents($securityFile);
        if ($contents === false) {
            return null;
        }

        // providers: … entity: class: App\Entity\User
        if (preg_match('/entity:\s*\n\s*class:\s*[\'"]?([A-Za-z0-9_\\\\]+)[\'"]?/m', $contents, $matches)) {
            return str_replace('\\\\', '\\', $matches[1]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function scanPhpForUserEntities(string $projectDir): array
    {
        $srcDir = $projectDir.'/src';
        if (!is_dir($srcDir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = file_get_contents($file->getPathname());
            if ($code === false) {
                continue;
            }

            $isEntity = str_contains($code, 'ORM\\Entity') || str_contains($code, '#[ORM\Entity') || str_contains($code, '@ORM\\Entity');
            $implementsUser = preg_match('/implements\s+[^{]*\b(UserInterface|PasswordAuthenticatedUserInterface)\b/', $code) === 1;
            $namedUser = preg_match('/\bclass\s+User\b/', $code) === 1;

            if (!$implementsUser && !($isEntity && $namedUser)) {
                continue;
            }

            if (!preg_match('/namespace\s+([A-Za-z0-9_\\\\]+)\s*;/', $code, $nsMatch)) {
                continue;
            }
            if (!preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $code, $classMatch)) {
                continue;
            }

            $shortName = $classMatch[1];
            if (str_ends_with($shortName, 'Repository') || str_ends_with($shortName, 'Controller')) {
                continue;
            }

            $found[] = $nsMatch[1].'\\'.$shortName;
        }

        sort($found);

        return $found;
    }

    private function guessIdentifierField(string $projectDir, string $userClass): ?string
    {
        // Standard Symfony PSR-4: App\ → src/
        if (!str_starts_with($userClass, 'App\\')) {
            return null;
        }

        $path = $projectDir.'/src/'.substr(str_replace('\\', '/', $userClass), \strlen('App/')).'.php';
        if (!is_file($path)) {
            return null;
        }

        $code = file_get_contents($path);
        if ($code === false) {
            return null;
        }

        foreach (['email', 'username', 'login'] as $field) {
            if (preg_match('/\$(?:'.$field.')\b/', $code) === 1) {
                return $field;
            }
        }

        return null;
    }

    private function writeUserClassConfig(
        string $configFile,
        string $contents,
        string $userClass,
        string $identifierField,
    ): bool {
        $updated = preg_replace(
            '/^(\s*)user_class:\s*.*$/m',
            '${1}user_class: '.$userClass,
            $contents,
            1
        );
        if (!\is_string($updated)) {
            return false;
        }

        $updated = preg_replace(
            '/^(\s*)user_identifier_field:\s*.*$/m',
            '${1}user_identifier_field: '.$identifierField,
            $updated,
            1
        );
        if (!\is_string($updated)) {
            return false;
        }

        return file_put_contents($configFile, $updated) !== false;
    }

    private function writeInstructions(IOInterface $io, bool $userClassConfigured): void
    {
        $io->write('');
        $io->write('  <bg=blue;fg=white> wpconsulting/touch-id-bundle </>');
        $io->write('');
        if ($userClassConfigured) {
            $io->write('  * <info>user_class</info> written in <comment>wp_consulting_touch_id.yaml</>.');
        } else {
            $io->write('  * Edit <comment>config/packages/wp_consulting_touch_id.yaml</> (user_class).');
        }
        $io->write('  * Implement <comment>TouchIdUserInterface</> on your User entity.');
        $io->write('  * Add PUBLIC_ACCESS for <comment>^/webauthn/login</> in security.yaml.');
        $io->write('  * Enable Stimulus controllers in <comment>assets/controllers.json</> (see README).');
        $io->write('  * Create the table: <comment>php bin/console doctrine:migrations:diff && doctrine:migrations:migrate</>');
        $io->write('  * Twig: <comment>touch_id_credentials(app.user)</> / global <comment>touch_id_manager</>.');
        $io->write('');
    }
}
