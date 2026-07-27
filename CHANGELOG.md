# Changelog

All notable changes to `wpconsulting/passkey-bundle` are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-07-27

First release under the **`wpconsulting/passkey-bundle`** name.

Replaces the former package **`wpconsulting/touch-id-bundle`** (Mac-oriented name). Same WebAuthn platform passkeys: Touch ID, Face ID, Samsung fingerprint, Windows Hello.

### Package rename

| Before (`touch-id-bundle`) | After (`passkey-bundle`) |
|---|---|
| `wpconsulting/touch-id-bundle` | `wpconsulting/passkey-bundle` |
| `WpConsulting\TouchIdBundle\` | `WpConsulting\PasskeyBundle\` |
| `TouchIdBundle` | `PasskeyBundle` |
| `TouchIdUserInterface` | `PasskeyUserInterface` |
| `wp_consulting_touch_id` | `wp_consulting_passkey` |
| `touch-id:configure` | `passkey:configure` |
| `@TouchId/touch_id/…` | `@Passkey/passkey/…` |
| `touch_id_credentials()` | `passkey_credentials()` |
| `@wpconsulting/touch-id-bundle` | `@wpconsulting/passkey-bundle` |

API routes stay under `/webauthn/*`. Entity table stays `web_authn_credential`.

### Features (carried from touch-id-bundle 2.x)

- `symfony-bundle` (no Composer plugin)
- `passkey:configure` / `passkey:install`
- Stimulus login/register + scoped CSS
- Twig partials + i18n (`fr` / `en` / `es` / `de`)
- PHPUnit + GitHub Actions CI

### Migration from `touch-id-bundle`

```bash
composer remove wpconsulting/touch-id-bundle
composer require wpconsulting/passkey-bundle:^1.0
php bin/console passkey:configure
```

Update Twig includes and `PasskeyUserInterface` on your User entity (configure can re-wire the interface).

Mark `wpconsulting/touch-id-bundle` as **abandoned** on Packagist with replacement `wpconsulting/passkey-bundle`.
