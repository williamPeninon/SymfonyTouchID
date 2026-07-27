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
 * Publishes skeleton files, prompts for user_class, and auto-wires:
 * TouchIdUserInterface, Stimulus controllers.json, and DB schema.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'wpconsulting/touch-id-bundle';

    private const DEFAULT_USER_CLASS = 'App\\Entity\\User';

    private const STIMULUS_CONTROLLERS = [
        'login' => [
            'enabled' => true,
            'fetch' => 'lazy',
        ],
        'register' => [
            'enabled' => true,
            'fetch' => 'lazy',
        ],
    ];

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
            'config/packages/dev/framework.yaml',
        ] as $relative) {
            $target = $projectDir.'/'.$relative;
            $source = $skeletonDir.'/'.$relative;
            if (!is_file($source)) {
                continue;
            }
            // Dev trusted proxies: skip if already configured elsewhere
            if ($relative === 'config/packages/dev/framework.yaml'
                && $this->projectAlreadyHasTrustedProxies($projectDir)
            ) {
                $io->write('    <info>trusted_proxies</info> already configured — skipping <comment>config/packages/dev/framework.yaml</comment>.');
                continue;
            }
            if (is_file($target)) {
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
        if ($shouldSetup) {
            $userClass = $this->resolveAndConfigureUserClass($projectDir, $io);
            $wiredUser = false;
            if ($userClass !== null) {
                $wiredUser = $this->ensureTouchIdUserInterface($projectDir, $userClass, $io);
            }
            $stimulusOk = $this->ensureStimulusControllers($projectDir, $io);
            $schemaOk = $this->ensureDatabaseSchema($projectDir, $io);
            $this->writeInstructions($io, $userClass, $wiredUser, $stimulusOk, $schemaOk);
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
     * True when trusted_proxies is already defined in any packages/*.yaml (any env).
     */
    private function projectAlreadyHasTrustedProxies(string $projectDir): bool
    {
        $packagesDir = $projectDir.'/config/packages';
        if (!is_dir($packagesDir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packagesDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\.(ya?ml)$/', $file->getFilename())) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (\is_string($contents) && preg_match('/^\s*trusted_proxies\s*:/m', $contents)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt for / read user_class and persist YAML. Returns resolved FQCN or null.
     */
    private function resolveAndConfigureUserClass(string $projectDir, IOInterface $io): ?string
    {
        $configFile = $projectDir.'/config/packages/wp_consulting_touch_id.yaml';
        if (!is_file($configFile)) {
            return null;
        }

        $contents = file_get_contents($configFile);
        if ($contents === false) {
            return null;
        }

        $existing = $this->readConfiguredUserClass($contents);
        if ($existing !== null) {
            $io->write(sprintf(
                '    <info>user_class</info> already set to <comment>%s</comment>.',
                $existing
            ));

            return $existing;
        }

        $candidates = $this->discoverUserClassCandidates($projectDir);
        $default = $candidates[0] ?? self::DEFAULT_USER_CLASS;

        if (!$io->isInteractive()) {
            $io->write(sprintf(
                '    <comment>Non-interactive install:</comment> leave <comment>user_class: ~</comment> (set later to e.g. <comment>%s</comment>).',
                $default
            ));

            return null;
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

            return null;
        }

        $io->write(sprintf(
            '    <fg=green>Configured</> user_class=<comment>%s</comment>, user_identifier_field=<comment>%s</comment>',
            $userClass,
            $identifierField
        ));

        return $userClass;
    }

    private function ensureTouchIdUserInterface(string $projectDir, string $userClass, IOInterface $io): bool
    {
        $path = $this->resolveUserClassFile($projectDir, $userClass);
        if ($path === null || !is_file($path)) {
            $io->write(sprintf(
                '    <comment>Could not locate</comment> <comment>%s</comment> — add <comment>TouchIdUserInterface</comment> manually.',
                $userClass
            ));

            return false;
        }

        $code = file_get_contents($path);
        if ($code === false) {
            return false;
        }

        $changed = false;
        $interfaceFqcn = 'WpConsulting\\TouchIdBundle\\Contract\\TouchIdUserInterface';

        if (!str_contains($code, 'TouchIdUserInterface')) {
            if (preg_match('/^use\s+[^;]+;/m', $code)) {
                $code = preg_replace(
                    '/^(use\s+[^;]+;\n)(?!use\s)/m',
                    '$1use '.$interfaceFqcn.";\n",
                    $code,
                    1
                );
            } else {
                $code = preg_replace(
                    '/(namespace\s+[^;]+;\s*\n)/',
                    '$1'."\n".'use '.$interfaceFqcn.";\n",
                    $code,
                    1
                );
            }
            $changed = true;
        }

        if (preg_match('/\bclass\s+\w+[^{]*\bimplements\b[^{]*\bTouchIdUserInterface\b/s', $code) !== 1) {
            $replaced = preg_replace(
                '/(\bclass\s+\w+(?:\s+extends\s+[^\s{]+)?\s+implements\s+)([^{\n]+?)(\s*\{)/',
                '$1$2, TouchIdUserInterface$3',
                $code,
                1,
                $count
            );
            if (\is_string($replaced) && $count > 0) {
                $code = $replaced;
                $changed = true;
            } else {
                $replaced = preg_replace(
                    '/(\bclass\s+\w+(?:\s+extends\s+[^\s{]+)?)(\s*)\{/',
                    '$1 implements TouchIdUserInterface$2{',
                    $code,
                    1,
                    $count2
                );
                if (\is_string($replaced) && $count2 > 0) {
                    $code = $replaced;
                    $changed = true;
                }
            }
        }

        $identifierField = $this->readConfiguredIdentifierField($projectDir)
            ?? $this->guessIdentifierField($projectDir, $userClass)
            ?? 'email';

        $methodsToAdd = [];
        if (!preg_match('/\bfunction\s+getUserId\s*\(/', $code)) {
            $methodsToAdd[] = <<<'PHP'

    public function getUserId(): mixed
    {
        return $this->id;
    }
PHP;
        }
        if (!preg_match('/\bfunction\s+getUserName\s*\(/', $code)) {
            $methodsToAdd[] = <<<PHP

    public function getUserName(): ?string
    {
        return \$this->{$identifierField};
    }
PHP;
        }
        if (!preg_match('/\bfunction\s+getUserDisplayName\s*\(/', $code)) {
            $methodsToAdd[] = <<<'PHP'

    public function getUserDisplayName(): string
    {
        return (string) $this->getUserName();
    }
PHP;
        }

        if ($methodsToAdd !== []) {
            $block = "\n    // TouchIdUserInterface".implode('', $methodsToAdd)."\n";
            $pos = strrpos($code, '}');
            if ($pos === false) {
                $io->writeError('  <error>Could not patch User entity (no closing brace).</error>');

                return false;
            }
            $code = substr($code, 0, $pos).$block.'}';
            $changed = true;
        }

        if (!$changed) {
            $io->write(sprintf(
                '    <info>TouchIdUserInterface</info> already present on <comment>%s</comment>.',
                $userClass
            ));

            return true;
        }

        if (file_put_contents($path, $code) === false) {
            $io->writeError(sprintf('  <error>Could not write %s</error>', $path));

            return false;
        }

        $io->write(sprintf(
            '    <fg=green>Wired</> <comment>TouchIdUserInterface</comment> on <comment>%s</comment>',
            $userClass
        ));

        return true;
    }

    private function ensureStimulusControllers(string $projectDir, IOInterface $io): bool
    {
        $file = $projectDir.'/assets/controllers.json';
        if (!is_file($file)) {
            $dir = \dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                $io->writeError('  <error>Could not create assets/controllers.json</error>');

                return false;
            }
            $data = ['controllers' => [], 'entrypoints' => []];
        } else {
            $raw = file_get_contents($file);
            $data = \is_string($raw) ? json_decode($raw, true) : null;
            if (!\is_array($data)) {
                $io->writeError('  <error>Invalid assets/controllers.json</error>');

                return false;
            }
        }

        if (!isset($data['controllers']) || !\is_array($data['controllers'])) {
            $data['controllers'] = [];
        }
        if (!isset($data['entrypoints']) || !\is_array($data['entrypoints'])) {
            $data['entrypoints'] = [];
        }

        $key = '@wpconsulting/touch-id-bundle';
        $current = $data['controllers'][$key] ?? null;
        if ($current === self::STIMULUS_CONTROLLERS) {
            $io->write('    <info>Stimulus</info> controllers already enabled in <comment>assets/controllers.json</comment>.');

            return true;
        }

        $data['controllers'][$key] = self::STIMULUS_CONTROLLERS;
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($file, $json) === false) {
            $io->writeError('  <error>Could not write assets/controllers.json</error>');

            return false;
        }

        $io->write('    <fg=green>Enabled</> Stimulus login/register in <comment>assets/controllers.json</comment>');

        return true;
    }

    private function ensureDatabaseSchema(string $projectDir, IOInterface $io): bool
    {
        $console = $projectDir.'/bin/console';
        if (!is_file($console)) {
            $io->write('    <comment>bin/console not found</comment> — create the table later with <comment>touch-id:install</comment>.');

            return false;
        }

        $php = \PHP_BINARY ?: 'php';

        // Prefer safe dedicated command (CREATE table only).
        [$ok, $output] = $this->runConsole($php, $console, ['touch-id:install', '-n']);
        if ($ok) {
            $io->write('    <fg=green>Database</> <comment>web_authn_credential</comment> ready (touch-id:install).');

            return true;
        }

        // Fallback: generate + run a migration if Doctrine Migrations is available.
        [$diffOk] = $this->runConsole($php, $console, ['doctrine:migrations:diff', '--no-interaction']);
        [$migrateOk, $migrateOut] = $this->runConsole($php, $console, [
            'doctrine:migrations:migrate',
            '--no-interaction',
            '--allow-no-migration',
        ]);

        if ($migrateOk) {
            $io->write('    <fg=green>Database</> migrated via <comment>doctrine:migrations</comment>.');

            return true;
        }

        $io->write('    <comment>Could not auto-create DB table</comment> — run:');
        $io->write('      <comment>php bin/console touch-id:install</comment>');
        $io->write('      or <comment>php bin/console doctrine:migrations:diff && doctrine:migrations:migrate</comment>');
        if ($output !== '') {
            $io->write('    <fg=gray>'.trim(preg_replace('/\s+/', ' ', $output) ?? $output).'</>');
        } elseif ($migrateOut !== '') {
            $io->write('    <fg=gray>'.trim(preg_replace('/\s+/', ' ', $migrateOut) ?? $migrateOut).'</>');
        }

        return false;
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: bool, 1: string}
     */
    private function runConsole(string $php, string $console, array $args): array
    {
        $cmd = array_merge([$php, $console], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $output = [];
        $code = 0;
        exec($escaped.' 2>&1', $output, $code);

        return [$code === 0, implode("\n", $output)];
    }

    private function resolveUserClassFile(string $projectDir, string $userClass): ?string
    {
        if (str_starts_with($userClass, 'App\\')) {
            $path = $projectDir.'/src/'.substr(str_replace('\\', '/', $userClass), \strlen('App/')).'.php';
            if (is_file($path)) {
                return $path;
            }
        }

        // Fallback: scan src for matching class declaration.
        $short = substr($userClass, (int) strrpos($userClass, '\\') + 1);
        $srcDir = $projectDir.'/src';
        if (!is_dir($srcDir)) {
            return null;
        }

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
            if (preg_match('/namespace\s+'.preg_quote(substr($userClass, 0, (int) strrpos($userClass, '\\')), '/').'\s*;/', $code)
                && preg_match('/\bclass\s+'.preg_quote($short, '/').'\b/', $code)) {
                return $file->getPathname();
            }
        }

        return null;
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

    private function readConfiguredIdentifierField(string $projectDir): ?string
    {
        $configFile = $projectDir.'/config/packages/wp_consulting_touch_id.yaml';
        if (!is_file($configFile)) {
            return null;
        }
        $contents = file_get_contents($configFile);
        if ($contents === false || !preg_match('/^\s*user_identifier_field:\s*(.+)\s*$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\"'");

        return $value !== '' ? $value : null;
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
        $path = $this->resolveUserClassFile($projectDir, $userClass);
        if ($path === null) {
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

    private function writeInstructions(
        IOInterface $io,
        ?string $userClass,
        bool $wiredUser,
        bool $stimulusOk,
        bool $schemaOk,
    ): void {
        $io->write('');
        $io->write('  <bg=blue;fg=white> wpconsulting/touch-id-bundle </>');
        $io->write('');
        if ($userClass !== null) {
            $io->write(sprintf('  * <info>user_class</info> = <comment>%s</comment>', $userClass));
        } else {
            $io->write('  * Edit <comment>config/packages/wp_consulting_touch_id.yaml</> (user_class).');
        }
        if (!$wiredUser) {
            $io->write('  * Implement <comment>TouchIdUserInterface</> on your User entity.');
        }
        $io->write('  * Add PUBLIC_ACCESS for <comment>^/webauthn/login</> in security.yaml (if missing).');
        if (!$stimulusOk) {
            $io->write('  * Enable Stimulus controllers in <comment>assets/controllers.json</>.');
        }
        if (!$schemaOk) {
            $io->write('  * Create the table: <comment>php bin/console touch-id:install</>.');
        }
        $io->write('  * Twig: include <comment>@TouchId/touch_id/_login_button.html.twig</> + <comment>_manage.html.twig</>.');
        $io->write('');
    }
}
