# WP Consulting Touch ID Bundle
#
# Symfony bundle for Mac Touch ID / platform WebAuthn (passkeys).

## Installation (Packagist)

```bash
composer require wpconsulting/touch-id-bundle
```

Enable the bundle (Flex usually does this):

```php
// config/bundles.php
WpConsulting\TouchIdBundle\TouchIdBundle::class => ['all' => true],
```

## Configuration

```yaml
# config/packages/wp_consulting_touch_id.yaml
wp_consulting_touch_id:
    user_class: App\Entity\User
    user_repository: App\Repository\UserRepository
    rp_name: 'My App'
    login_authenticator: form_login
    default_redirect_route: app_account
    success_handler: App\Security\LoginSuccessHandler # optional
    translation_domain: messages
    translation_prefix: 'account.webauthn.'
```

```yaml
# config/routes/touch_id.yaml
touch_id:
    resource: '@TouchIdBundle/config/routes.yaml'
```

```yaml
# config/packages/security.yaml (public login endpoints)
access_control:
    - { path: ^/webauthn/login, roles: PUBLIC_ACCESS }
```

## User requirements

Your User entity must implement `TouchIdUserInterface`:

```php
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

class User implements UserInterface, TouchIdUserInterface
{
    public function getTouchIdDisplayName(): string
    {
        return $this->getFullName() ?: (string) $this->getEmail();
    }
}
```

Your user repository must implement `TouchIdUserRepositoryInterface`:

```php
use WpConsulting\TouchIdBundle\Contract\TouchIdUserRepositoryInterface;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

class UserRepository extends ServiceEntityRepository implements TouchIdUserRepositoryInterface
{
    public function findOneForTouchIdByEmail(string $email): ?TouchIdUserInterface
    {
        $user = $this->findOneBy(['email' => $email]);

        return $user instanceof TouchIdUserInterface ? $user : null;
    }
}
```

## Database

Create the `web_authn_credential` table (Doctrine migration):

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Stimulus controllers

```json
// assets/controllers.json
{
    "controllers": {
        "@wpconsulting/touch-id-bundle": {
            "login": { "enabled": true, "fetch": "lazy" },
            "register": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

## Twig

Login button:

```twig
{% include '@TouchId/touch_id/_login_button.html.twig' %}
```

Manage fingerprints (authenticated account page):

```twig
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_manager.listCredentials(app.user)
} %}
```

Or inject `TouchIdManager` in your controller and pass `webAuthnCredentials`.

## Local development note

WebAuthn rejects IP addresses. Use `https://localhost:PORT` (not `127.0.0.1`).

## Publish on Packagist

1. Push this package to a public Git repository
2. Submit the repository URL on https://packagist.org/packages/submit
3. Tag a release: `git tag v1.0.0 && git push --tags`
