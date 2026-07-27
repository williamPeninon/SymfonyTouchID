# Changelog

All notable changes to `wpconsulting/touch-id-bundle` are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [Semantic Versioning](https://semver.org/).

---

## [2.0.0] — 2026-07-27

Fresh major line. Prefer `^2.0` on Packagist going forward.

### Breaking

- Package type is now **`symfony-bundle`** (no longer a forced Composer plugin).
- Removed dependency on `composer-plugin-api`.
- Auto-wiring on `composer require` is **gone**. Use `php bin/console touch-id:configure` instead.
- You can remove `allow-plugins.wpconsulting/touch-id-bundle` from the host `composer.json` if it was only there for this package.

### Added

- Command `touch-id:configure` — publishes skeleton config/routes, registers the bundle, sets `user_class`, wires `TouchIdUserInterface`, enables Stimulus controllers, adds `PUBLIC_ACCESS` for `^/webauthn/login`, creates the DB table.
- Command `touch-id:install` still available for the `web_authn_credential` table only.
- `config/packages/dev/framework.yaml` skeleton (`trusted_proxies` for ngrok — **dev only**).
- PHPUnit suite + GitHub Actions CI (PHP 8.2 / 8.3 / 8.4).
- `CHANGELOG.md`, `SECURITY.md`, `.gitattributes`.
- Packagist metadata: `homepage`, `support.issues`, `support.source`.

### Changed

- Installer services (`touch-id:configure`) load even when `user_class` is not yet wired (fixes first-time setup).
- Docs rewritten for the configure-based install flow.
- Stimulus assets package version aligned to `2.0.0`.

### Upgrade from 1.x

```bash
composer require wpconsulting/touch-id-bundle:^2.0
# optional: remove allow-plugins.wpconsulting/touch-id-bundle
php bin/console touch-id:configure
```

Twig includes remain manual:

```twig
{% include '@TouchId/touch_id/_login_button.html.twig' %}
{% include '@TouchId/touch_id/_manage.html.twig' with { credentials: touch_id_credentials(app.user) } %}
```

### Notes on 1.5 / 1.6

- **1.5.x** — Composer plugin era (auto-wiring on install). Maintenance only; no new features.
- **1.6.x** — short transition to `symfony-bundle` + `touch-id:configure`. Superseded by **2.0.0** (same model, clean major + docs).

---

## [1.6.1] — 2026-07-27

- Always register `touch-id:configure` before `user_class` is fully wired.

## [1.6.0] — 2026-07-27

- First `symfony-bundle` release with `touch-id:configure` (see 2.0.0 for the canonical upgrade path).

## [1.5.x] — 2026-07

- Composer plugin install flow, WebAuthn platform passkeys, Stimulus UX, i18n (`fr`/`en`/`es`/`de`).
- See Git tags `v1.5.*` for patch history.
