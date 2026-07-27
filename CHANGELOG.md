# Changes since 1.5 — see git tags for earlier history.

## [1.6.0] — 2026-07-27

### Changed
- Package type is now `symfony-bundle` (no longer a forced Composer plugin).
- Interactive / automatic wiring moved to `php bin/console touch-id:configure`.
- `composer-plugin-api` dependency removed.

### Added
- `ProjectConfigurator` installer service + `touch-id:configure` command.
- PHPUnit test suite and GitHub Actions CI.
- `CHANGELOG.md`, `SECURITY.md`, `.gitattributes`.
- `homepage` / `support` metadata in `composer.json`.
- Auto `PUBLIC_ACCESS` for `^/webauthn/login` during configure.

### Upgrade from 1.5.x
1. `composer update wpconsulting/touch-id-bundle`
2. If plugins were allowed only for this package, you can remove `allow-plugins.wpconsulting/touch-id-bundle`.
3. Run `php bin/console touch-id:configure` after install/update when wiring is incomplete.
4. Twig includes remain manual (`_login_button` / `_manage`).
