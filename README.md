# WP Consulting Touch ID Bundle

Symfony bundle for platform WebAuthn passkeys: Apple Face ID / Touch ID, Samsung / Android fingerprint & face, Windows Hello.

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
    # Built-in CTA strings (fr/en/es/de) — override domain/prefix only if needed
    translation_domain: TouchIdBundle
    translation_prefix: ''
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

Login CTA:

```twig
{% include '@TouchId/touch_id/_login_button.html.twig' %}
```

Manage biometrics (authenticated account page):

```twig
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_manager.listCredentials(app.user)
} %}
```

Strings for these CTAs ship in the bundle (`TouchIdBundle` domain: `fr`, `en`, `es`, `de`). Override any key in your app translations if needed.

## Google Cloud Console (Android / Samsung Credential Manager)

Web-only passkeys (Chrome / Samsung Internet on HTTPS) work **without** Google Cloud.
Use [Google Cloud Console](https://console.cloud.google.com/) when you also ship a **native Android app** and want Google Password Manager / Credential Manager to share passkeys between the app and your website ([Digital Asset Links](https://developers.google.com/identity/credential-sharing/set-up)).

### 1. Create or select a project

1. Open https://console.cloud.google.com/
2. Select an existing project, or **New project** → name it (e.g. `my-app-passkeys`) → **Create**

### 2. Register an Android OAuth client (SHA-256)

1. Menu **APIs & Services** → **Credentials**
2. **+ Create credentials** → **OAuth client ID**
3. If prompted, configure the OAuth consent screen (External / Internal)
4. Application type: **Android**
5. Package name: your app id (e.g. `com.example.myapp`)
6. **SHA-256 certificate fingerprint**:
   - Debug: `keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android`
   - Release / Play App Signing: Play Console → your app → **Setup** → **App signing** → copy **SHA-256**
7. **Create** and keep the client

### 3. Publish Digital Asset Links on your website

Host this file at `https://YOUR_DOMAIN/.well-known/assetlinks.json` (`Content-Type: application/json`, HTTP 200, no redirect):

```json
[
  {
    "relation": [
      "delegate_permission/common.handle_all_urls",
      "delegate_permission/common.get_login_creds"
    ],
    "target": {
      "namespace": "android_app",
      "package_name": "com.example.myapp",
      "sha256_cert_fingerprints": [
        "AA:BB:CC:...:FF"
      ]
    }
  }
]
```

Use the same SHA-256 as in the Cloud Console Android OAuth client.
See [Credential Manager prerequisites](https://developer.android.com/identity/credential-manager/prerequisites).

### 4. Checklist before testing on Samsung / Android

- Site served over **HTTPS** (or `localhost` for local web-only tests)
- WebAuthn **RP ID** = the public hostname (same URL for register and login; ngrok ≠ production ≠ localhost)
- User registers a passkey **on that device** via your account page (phone unlock fingerprint alone is not a website passkey)
- If you have a native app: `assetlinks.json` reachable and fingerprints match Cloud Console / Play signing

## Supported devices

- **Mac** → Touch ID
- **iPhone / iPad** → Face ID (or Touch ID on older devices)
- **Samsung / Android** → fingerprint / face (Credential Manager)
- **Windows** → Windows Hello

## Local development note

WebAuthn rejects IP addresses. Use `https://localhost:PORT` or `http://localhost:PORT` (not `127.0.0.1`).

## Publish on Packagist

1. Push this package to a public Git repository
2. Submit the repository URL on https://packagist.org/packages/submit
3. Tag a release: `git tag v1.2.0 && git push --tags`
