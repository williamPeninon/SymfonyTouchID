# Changelog

All notable changes to this package are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [Semantic Versioning](https://semver.org/).

---

## [3.0.3] — 2026-07-27

### Docs

- English `README.md` (Packagist default) + French `README.fr.md` with cross-links.

---

## [3.0.2] — 2026-07-27

### Added

- Message post-install Composer : commande `passkey:configure` + détail des étapes.
  (plugin d’affichage uniquement — autoriser `wpconsulting/passkey-bundle` dans `allow-plugins`)

---


## [3.0.1] — 2026-07-27

### Changed

- Remove `wpconsulting.fr` links from README / docs (LinkedIn only).

---

## [3.0.0] — 2026-07-27

### Renamed package

**`wpconsulting/touch-id-bundle` → `wpconsulting/passkey-bundle`**

The Mac-centric “Touch ID” name is dropped. The bundle still covers platform passkeys: Touch ID, Face ID, Samsung fingerprint, Windows Hello.

| Before | After |
|---|---|
| `wpconsulting/touch-id-bundle` | `wpconsulting/passkey-bundle` |
| `WpConsulting\TouchIdBundle\` | `WpConsulting\PasskeyBundle\` |
| `TouchIdUserInterface` | `PasskeyUserInterface` |
| `wp_consulting_touch_id` | `wp_consulting_passkey` |
| `touch-id:configure` | `passkey:configure` |
| `@TouchId/touch_id/…` | `@Passkey/passkey/…` |
| `touch_id_credentials()` | `passkey_credentials()` |
| `@wpconsulting/touch-id-bundle` | `@wpconsulting/passkey-bundle` |

Unchanged: `/webauthn/*` routes, `web_authn_credential` table.

### Migration

```bash
composer remove wpconsulting/touch-id-bundle
composer require wpconsulting/passkey-bundle:^3.0
php bin/console passkey:configure
```

Update Twig includes to `@Passkey/passkey/…`.

On Packagist, mark `touch-id-bundle` as **abandoned** with replacement `wpconsulting/passkey-bundle`.

---

## Previous line (`touch-id-bundle`)

| Version | Notes |
|---|---|
| 2.0.x | `symfony-bundle` + `touch-id:configure` |
| 1.6.x | Short transition (superseded by 2.0) |
| 1.5.x | Composer plugin era |

See git tags `v2.0.0`, `v1.6.*`, `v1.5.*`.
