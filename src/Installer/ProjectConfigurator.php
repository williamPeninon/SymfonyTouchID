<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Installer;

/**
 * Idempotent project wiring used by touch-id:configure (no Composer plugin).
 *
 * @internal
 */
final class ProjectConfigurator
{
    public const DEFAULT_USER_CLASS = 'App\\Entity\\User';

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

    private const SKELETON_FILES = [
        'config/packages/wp_consulting_touch_id.yaml',
        'config/routes/touch_id.yaml',
        'config/packages/dev/framework.yaml',
    ];

    /**
     * @return list<string> Relative paths created
     */
    public function publishSkeleton(string $projectDir, string $bundleRootDir): array
    {
        $skeletonDir = rtrim($bundleRootDir, '/').'/skeleton';
        if (!is_dir($skeletonDir)) {
            return [];
        }

        $created = [];
        foreach (self::SKELETON_FILES as $relative) {
            $target = $projectDir.'/'.$relative;
            $source = $skeletonDir.'/'.$relative;
            if (!is_file($source) || is_file($target)) {
                continue;
            }
            if ($relative === 'config/packages/dev/framework.yaml'
                && $this->projectAlreadyHasTrustedProxies($projectDir)
            ) {
                continue;
            }
            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                continue;
            }
            if (copy($source, $target)) {
                $created[] = $relative;
            }
        }

        return $created;
    }

    public function ensureBundleRegistered(string $projectDir): bool
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

        return file_put_contents($bundlesFile, $updated) !== false;
    }

    public function projectAlreadyHasTrustedProxies(string $projectDir): bool
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

    public function readConfiguredUserClassFromProject(string $projectDir): ?string
    {
        $configFile = $projectDir.'/config/packages/wp_consulting_touch_id.yaml';
        if (!is_file($configFile)) {
            return null;
        }
        $contents = file_get_contents($configFile);

        return \is_string($contents) ? $this->readConfiguredUserClass($contents) : null;
    }

    public function readConfiguredUserClass(string $yaml): ?string
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

    public function readConfiguredIdentifierField(string $projectDir): ?string
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
    public function discoverUserClassCandidates(string $projectDir): array
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

    public function detectUserClassFromSecurity(string $projectDir): ?string
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
    public function scanPhpForUserEntities(string $projectDir): array
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

    public function guessIdentifierField(string $projectDir, string $userClass): ?string
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

    public function writeUserClassConfig(
        string $projectDir,
        string $userClass,
        string $identifierField,
    ): bool {
        $configFile = $projectDir.'/config/packages/wp_consulting_touch_id.yaml';
        if (!is_file($configFile)) {
            return false;
        }
        $contents = file_get_contents($configFile);
        if ($contents === false) {
            return false;
        }

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

    /**
     * @return array{changed: bool, path: ?string, error: ?string}
     */
    public function ensureTouchIdUserInterface(string $projectDir, string $userClass): array
    {
        $path = $this->resolveUserClassFile($projectDir, $userClass);
        if ($path === null || !is_file($path)) {
            return ['changed' => false, 'path' => null, 'error' => 'User class file not found'];
        }

        $code = file_get_contents($path);
        if ($code === false) {
            return ['changed' => false, 'path' => $path, 'error' => 'Could not read User class file'];
        }

        $changed = false;
        $interfaceFqcn = 'WpConsulting\\TouchIdBundle\\Contract\\TouchIdUserInterface';

        if (!str_contains($code, 'TouchIdUserInterface')) {
            if (preg_match('/^use\s+[^;]+;/m', $code)) {
                $replaced = preg_replace(
                    '/^(use\s+[^;]+;\n)(?!use\s)/m',
                    '$1use '.$interfaceFqcn.";\n",
                    $code,
                    1
                );
            } else {
                $replaced = preg_replace(
                    '/(namespace\s+[^;]+;\s*\n)/',
                    '$1'."\n".'use '.$interfaceFqcn.";\n",
                    $code,
                    1
                );
            }
            if (\is_string($replaced)) {
                $code = $replaced;
                $changed = true;
            }
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
                return ['changed' => false, 'path' => $path, 'error' => 'No closing brace in User class'];
            }
            $code = substr($code, 0, $pos).$block.'}';
            $changed = true;
        }

        if (!$changed) {
            return ['changed' => false, 'path' => $path, 'error' => null];
        }

        if (file_put_contents($path, $code) === false) {
            return ['changed' => false, 'path' => $path, 'error' => 'Could not write User class file'];
        }

        return ['changed' => true, 'path' => $path, 'error' => null];
    }

    public function ensureStimulusControllers(string $projectDir): bool
    {
        $file = $projectDir.'/assets/controllers.json';
        if (!is_file($file)) {
            $dir = \dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                return false;
            }
            $data = ['controllers' => [], 'entrypoints' => []];
        } else {
            $raw = file_get_contents($file);
            $data = \is_string($raw) ? json_decode($raw, true) : null;
            if (!\is_array($data)) {
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
        if (($data['controllers'][$key] ?? null) === self::STIMULUS_CONTROLLERS) {
            return true;
        }

        $data['controllers'][$key] = self::STIMULUS_CONTROLLERS;
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        return file_put_contents($file, $json) !== false;
    }

    public function ensureWebauthnLoginAccess(string $projectDir): bool
    {
        $securityFile = $projectDir.'/config/packages/security.yaml';
        if (!is_file($securityFile)) {
            return false;
        }

        $contents = file_get_contents($securityFile);
        if ($contents === false) {
            return false;
        }

        if (str_contains($contents, '^/webauthn/login') || str_contains($contents, '^/webauthn')) {
            return true;
        }

        if (!preg_match('/^(\s*)access_control:\s*$/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $indent = $m[1][0].'    ';
        $line = "\n".$indent.'- { path: ^/webauthn/login, roles: PUBLIC_ACCESS }';
        $pos = $m[0][1] + \strlen($m[0][0]);
        $updated = substr($contents, 0, $pos).$line.substr($contents, $pos);

        return file_put_contents($securityFile, $updated) !== false;
    }

    public function resolveUserClassFile(string $projectDir, string $userClass): ?string
    {
        if (str_starts_with($userClass, 'App\\')) {
            $path = $projectDir.'/src/'.substr(str_replace('\\', '/', $userClass), \strlen('App/')).'.php';
            if (is_file($path)) {
                return $path;
            }
        }

        $short = substr($userClass, (int) strrpos($userClass, '\\') + 1);
        $ns = substr($userClass, 0, (int) strrpos($userClass, '\\'));
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
            if (preg_match('/namespace\s+'.preg_quote($ns, '/').'\s*;/', $code)
                && preg_match('/\bclass\s+'.preg_quote($short, '/').'\b/', $code)) {
                return $file->getPathname();
            }
        }

        return null;
    }

    public static function isValidUserClassFqcn(string $answer): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $answer);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: bool, 1: string}
     */
    public function runConsole(string $projectDir, array $args): array
    {
        $console = $projectDir.'/bin/console';
        if (!is_file($console)) {
            return [false, 'bin/console not found'];
        }

        $php = \PHP_BINARY ?: 'php';
        $cmd = array_merge([$php, $console], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $output = [];
        $code = 0;
        exec($escaped.' 2>&1', $output, $code);

        return [$code === 0, implode("\n", $output)];
    }
}
