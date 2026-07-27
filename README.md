# WP Consulting Passkey Bundle

**Language:** [English](README.md) · [Français](README.fr.md)

[![CI](https://github.com/williamPeninon/passkey-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/williamPeninon/passkey-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/wpconsulting/passkey-bundle.svg)](https://packagist.org/packages/wpconsulting/passkey-bundle)
[![License](https://img.shields.io/github/license/williamPeninon/passkey-bundle.svg)](LICENSE)

Symfony bundle for **WebAuthn / passkeys** using the device’s platform authenticator:

| Platform          | Method                                              |
| ----------------- | --------------------------------------------------- |
| Mac               | **Touch ID**                                        |
| iPhone / iPad     | **Face ID** (or Touch ID)                           |
| Samsung / Android | **Fingerprint** (Credential Manager / Samsung Pass) |
| Windows           | **Windows Hello**                                   |

> Unlocking the phone or Mac **does not** create a passkey for your site. Users must **register** a passkey from their logged-in account, on the **same device** and the **same hostname** (RP ID).

---

## Requirements

- PHP `>= 8.2`
- Symfony `^6.4` or `^7.0`
- Doctrine ORM + Security + Twig + Stimulus (Asset Mapper / UX)

---

## Quick install

```bash
composer require wpconsulting/passkey-bundle
php bin/console passkey:configure
```

Then add the Twig partials (only remaining manual wiring):

**Login**

```twig
{% include '@Passkey/passkey/_login_button.html.twig' %}
```

**Account (manage passkeys)**

```twig
{% include '@Passkey/passkey/_manage.html.twig' with {
    credentials: passkey_credentials(app.user)
} %}
```

### What `passkey:configure` does

- publishes `config/packages/wp_consulting_passkey.yaml` and `config/routes/passkey.yaml`
- publishes `config/packages/dev/framework.yaml` (`trusted_proxies` for ngrok — **dev only**)
- registers the bundle in `config/bundles.php` if needed
- asks for `user_class` + `user_identifier_field`
- implements `PasskeyUserInterface` on the User entity
- enables Stimulus controllers in `assets/controllers.json`
- adds `PUBLIC_ACCESS` for `^/webauthn/login`
- creates the `web_authn_credential` table (`passkey:install` / migrations)

```bash
# Examples
php bin/console passkey:configure --user-class=App\\Entity\\User
php bin/console passkey:configure --no-db
php bin/console passkey:configure -n   # non-interactive if user_class is already in YAML
```

### Flex (optional)

To also copy YAML via Symfony Flex:

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://raw.githubusercontent.com/williamPeninon/passkey-bundle/main/flex/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

Without a custom endpoint, Flex may show an `auto-generated recipe` — that is fine.
The Composer plugin then prints the post-install message (`passkey:configure` + details).
Allow it if Composer asks:

```json
{
    "config": {
        "allow-plugins": {
            "wpconsulting/passkey-bundle": true
        }
    }
}
```

---

## Configuration

```yaml
# config/packages/wp_consulting_passkey.yaml
wp_consulting_passkey:
    user_class: App\Entity\User          # entity FQCN, not a namespace
    user_identifier_field: email         # Doctrine field used for login lookup
    rp_name: 'My App'
    login_authenticator: form_login
    default_redirect_route: app_account
    # success_handler: App\Security\LoginSuccessHandler
    translation_domain: PasskeyBundle
    translation_prefix: ''
    email_input_selector: '#username, input[name="_username"], input[name="email"], input[type="email"]'
```

| Option                   | Purpose                                                      |
| ------------------------ | ------------------------------------------------------------ |
| `user_class`             | User entity (must implement `PasskeyUserInterface`)          |
| `user_identifier_field`  | Field used at WebAuthn login (`email`, `username`, …)        |
| `rp_name`                | Name shown in the passkey dialog                             |
| `login_authenticator`    | Security authenticator passed to `Security::login()`         |
| `default_redirect_route` | Redirect after biometric login                               |
| `success_handler`        | Optional post-login handler                                  |
| `email_input_selector`   | CSS selectors for the identifier field on the login form     |

Until `user_class` is a valid class implementing `PasskeyUserInterface`, business services are not wired: `cache:clear` / `asset-map:compile` still work. The `passkey:configure` command is **always** available.

### User

```php
use WpConsulting\PasskeyBundle\Contract\PasskeyUserInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface, PasskeyUserInterface
{
    public function getUserId(): mixed
    {
        return $this->id;
    }

    public function getUserName(): ?string
    {
        return $this->email;
    }

    public function getUserDisplayName(): string
    {
        return (string) $this->getUserName();
    }
}
```

### Security / firewalls

`passkey:configure` adds:

```yaml
access_control:
    - { path: ^/webauthn/login, roles: PUBLIC_ACCESS }
```

If you have a **separate admin firewall**, share the session context:

```yaml
security:
    firewalls:
        main: { /* … */ }
        admin:
            pattern: ^/admin
            context: main
```

Otherwise the session created on `/webauthn/*` is invisible to admin → 401/403.

### Database

```bash
php bin/console passkey:install
# or
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Check:

```bash
php bin/console doctrine:mapping:info
# → WpConsulting\PasskeyBundle\Entity\WebAuthnCredential
```

---

## Twig

### Login — `_login_button.html.twig`

```twig
{% include '@Passkey/passkey/_login_button.html.twig' with {
    redirect_url: path('app_account'),
    show_divider: true
} %}
```

Options: `email_input`, `redirect_url`, `button_class`, `show_hint`, `show_divider`, i18n messages…

### Account — `_manage.html.twig`

```twig
{% include '@Passkey/passkey/_manage.html.twig' with {
    credentials: passkey_credentials(app.user)
} %}
```

Options: `add_button_class`, `wrapper_class`, i18n labels…

### Helpers

| Helper                         | Description                 |
| ------------------------------ | --------------------------- |
| `passkey_credentials(user)`    | List of passkeys            |
| `passkey_manager`              | `PasskeyManager` service    |
| `passkey_redirect_path`        | Post-login redirect URL     |
| `passkey_email_input_selector` | Email CSS selectors         |
| `passkey_translation_domain`   | Translation domain          |

CTA translations: `PasskeyBundle` (`fr`, `en`, `es`, `de`).

### CSS

Bundled styles (`assets/styles/passkey.css`), loaded via Stimulus `autoimport`.

```css
.passkey-manage {
    --pk-accent: #0f766e;
    --pk-ink: #1a2332;
}
```

---

## How it works

1. Classic login (email / password).
2. On the account page, **Add** Touch ID / Face ID / fingerprint.
3. System dialog → public key stored in the DB (`web_authn_credential`).
4. On `/login`, the biometric button calls `navigator.credentials.get` and authenticates the user.

|       | Device unlock           | Site passkey                          |
| ----- | ----------------------- | ------------------------------------- |
| Role  | Opens the screen / Mac  | Signs in to the **app account**       |
| Where | System settings         | Account page **on the site**          |
| Tied to | The device            | Account + **hostname** (RP ID)        |

A passkey created on `localhost` **does not work** on `xxx.ngrok-free.app` or production (different RP ID): re-register on each hostname.

---

## Platforms

### Mac (Touch ID)

- Touch ID sensor + recent Safari / Chrome.
- Open the site at `http://localhost:…` or `https://localhost:…` (**not** `127.0.0.1`).
- No Face ID on Mac.

### iPhone / iPad (Face ID)

- Safari (or Chrome iOS / WebKit).
- Same hostname as login; public HTTPS or ngrok tunnel **without** `--host-header=localhost`.

### Samsung / Android (fingerprint)

- Web passkeys = **fingerprint** (strong biometrics), not lock-screen face unlock.
- Chrome / Samsung Internet over HTTPS.
- “No biometrics” on login = **no passkey registered** for this account / device / hostname.

### Windows Hello

- Fingerprint, PIN, or camera depending on hardware; Edge / Chrome.

| Device        | UI label            | Passkey method  |
| ------------- | ------------------- | --------------- |
| Mac           | Touch ID            | Mac fingerprint |
| iPhone / iPad | Face ID             | Face / Touch ID |
| Samsung       | Samsung Fingerprint | Fingerprint     |
| Android       | Fingerprint         | Fingerprint     |
| Windows       | Windows Hello       | Hello           |

---

## Local dev & ngrok

- **Forbidden:** `127.0.0.1`, bare IPs → invalid WebAuthn domain.
- **OK:** `localhost`, public HTTPS hostname.
- **ngrok:** do not force `Host: localhost`; the Host / `X-Forwarded-Host` seen by Symfony must be the public hostname.
- In dev, `passkey:configure` may publish `config/packages/dev/framework.yaml` (`trusted_proxies`). **Do not reuse those values in prod** — see [SECURITY.md](SECURITY.md).

**Web-only** passkeys do **not** require a Google Cloud / OAuth project. Google OAuth or Digital Asset Links only apply to Google Sign-In / native apps linked to the site.

---

## Commands

| Command             | Purpose                                         |
| ------------------- | ----------------------------------------------- |
| `passkey:configure` | Full host-app wiring                            |
| `passkey:install`   | Creates only the `web_authn_credential` table   |

---

## Migration from `touch-id-bundle`

Previous package: `wpconsulting/touch-id-bundle` (abandoned).

```bash
composer remove wpconsulting/touch-id-bundle
composer require wpconsulting/passkey-bundle:^3.0
php bin/console passkey:configure
```

Update Twig includes (`@Passkey/passkey/…`) and the `PasskeyUserInterface` interface.

Details: [CHANGELOG.md](CHANGELOG.md).

---

## Security & contributing

- Reporting: [SECURITY.md](SECURITY.md)
- Changelog: [CHANGELOG.md](CHANGELOG.md)
- Issues: [GitHub Issues](https://github.com/williamPeninon/passkey-bundle/issues)
- Tests: `composer test`

```bash
composer install
composer test
```

---

## License

MIT © [WP Consulting](https://www.linkedin.com/in/william-peninon-cto-yuno)
