<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Tests\Installer;

use PHPUnit\Framework\TestCase;
use WpConsulting\PasskeyBundle\Installer\ProjectConfigurator;

final class ProjectConfiguratorTest extends TestCase
{
    private string $tmpdir;

    private ProjectConfigurator $configurator;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir().'/passkey-cfg-'.uniqid('', true);
        mkdir($this->tmpdir.'/config/packages', 0777, true);
        mkdir($this->tmpdir.'/config/routes', 0777, true);
        mkdir($this->tmpdir.'/src/Entity', 0777, true);
        mkdir($this->tmpdir.'/assets', 0777, true);
        $this->configurator = new ProjectConfigurator();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpdir);
    }

    public function testReadConfiguredUserClassNullish(): void
    {
        self::assertNull($this->configurator->readConfiguredUserClass("user_class: ~\n"));
        self::assertNull($this->configurator->readConfiguredUserClass("user_class: null\n"));
        self::assertSame('App\\Entity\\User', $this->configurator->readConfiguredUserClass("user_class: App\\Entity\\User\n"));
    }

    public function testIsValidUserClassFqcn(): void
    {
        self::assertTrue(ProjectConfigurator::isValidUserClassFqcn('App\\Entity\\User'));
        self::assertTrue(ProjectConfigurator::isValidUserClassFqcn('App\\Iam\\Auth\\Entity\\User'));
        self::assertFalse(ProjectConfigurator::isValidUserClassFqcn('User'));
        self::assertFalse(ProjectConfigurator::isValidUserClassFqcn('App/Entity/User'));
    }

    public function testDetectUserClassFromSecurity(): void
    {
        file_put_contents($this->tmpdir.'/config/packages/security.yaml', <<<'YAML'
security:
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email
YAML);
        self::assertSame('App\\Entity\\User', $this->configurator->detectUserClassFromSecurity($this->tmpdir));
    }

    public function testPublishSkeletonAndWriteUserClass(): void
    {
        $bundleRoot = \dirname(__DIR__, 2);
        $created = $this->configurator->publishSkeleton($this->tmpdir, $bundleRoot);
        self::assertContains('config/packages/wp_consulting_passkey.yaml', $created);
        self::assertContains('config/routes/passkey.yaml', $created);

        self::assertTrue($this->configurator->writeUserClassConfig($this->tmpdir, 'App\\Entity\\User', 'email'));
        $yaml = file_get_contents($this->tmpdir.'/config/packages/wp_consulting_passkey.yaml');
        self::assertIsString($yaml);
        self::assertSame('App\\Entity\\User', $this->configurator->readConfiguredUserClass($yaml));
        self::assertStringContainsString('user_identifier_field: email', $yaml);
    }

    public function testEnsureStimulusControllers(): void
    {
        file_put_contents($this->tmpdir.'/assets/controllers.json', json_encode([
            'controllers' => [],
            'entrypoints' => [],
        ], \JSON_THROW_ON_ERROR));

        self::assertTrue($this->configurator->ensureStimulusControllers($this->tmpdir));
        $data = json_decode((string) file_get_contents($this->tmpdir.'/assets/controllers.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('@wpconsulting/passkey-bundle', $data['controllers']);
        self::assertTrue($data['controllers']['@wpconsulting/passkey-bundle']['login']['enabled']);
    }

    public function testEnsurePasskeyUserInterface(): void
    {
        file_put_contents($this->tmpdir.'/src/Entity/User.php', <<<'PHP'
<?php
namespace App\Entity;
use Symfony\Component\Security\Core\User\UserInterface;
class User implements UserInterface
{
    private ?int $id = null;
    private ?string $email = null;
    public function getUserIdentifier(): string { return (string) $this->email; }
    public function getRoles(): array { return []; }
    public function eraseCredentials(): void {}
}
PHP);
        file_put_contents($this->tmpdir.'/config/packages/wp_consulting_passkey.yaml', <<<'YAML'
wp_consulting_passkey:
    user_class: App\Entity\User
    user_identifier_field: email
YAML);

        $result = $this->configurator->ensurePasskeyUserInterface($this->tmpdir, 'App\\Entity\\User');
        self::assertNull($result['error']);
        self::assertTrue($result['changed']);
        $code = (string) file_get_contents($this->tmpdir.'/src/Entity/User.php');
        self::assertStringContainsString('PasskeyUserInterface', $code);
        self::assertStringContainsString('function getUserId', $code);
        self::assertStringContainsString('function getUserName', $code);
        self::assertDoesNotMatchRegularExpression('/implements[^{]*\n,\s*PasskeyUserInterface/', $code);
    }

    public function testEnsureWebauthnLoginAccess(): void
    {
        file_put_contents($this->tmpdir.'/config/packages/security.yaml', <<<'YAML'
security:
    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
YAML);
        self::assertTrue($this->configurator->ensureWebauthnLoginAccess($this->tmpdir));
        $yaml = (string) file_get_contents($this->tmpdir.'/config/packages/security.yaml');
        self::assertStringContainsString('^/webauthn/login', $yaml);
    }

    public function testEnsureBundleRegistered(): void
    {
        file_put_contents($this->tmpdir.'/config/bundles.php', <<<'PHP'
<?php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
];
PHP);
        self::assertTrue($this->configurator->ensureBundleRegistered($this->tmpdir));
        $contents = (string) file_get_contents($this->tmpdir.'/config/bundles.php');
        self::assertStringContainsString('PasskeyBundle::class', $contents);
        self::assertFalse($this->configurator->ensureBundleRegistered($this->tmpdir));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
