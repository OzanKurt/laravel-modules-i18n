# Changelog

All notable changes follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **REST API on the Core API kit**: the JSON API is now built on `ozankurt/laravel-modules-core` `^2.0`. It registers under `config('i18n.http.prefix')` (default `api/i18n`) via `registerModuleApi()`, responses use the `{ data, meta }` envelope, and it is throttled by the `i18n-api` limiter. New resource endpoints round out the surface: `GET /api/i18n/groups` (list all translation groups), `GET /api/i18n/locales` (list locales), and single-key `GET`/`PUT`/`DELETE /api/i18n/translations` over the same safe write path.
- **Cross-group missing-key report**: `MissingKeyReport` support class and `GET /api/i18n/report/missing?reference={locale}` endpoint that list, across every JSON and PHP group at once, the reference keys absent from each target locale.
- **Import / export**: `TranslationExporter` + `TranslationImporter` support classes and `GET /api/i18n/export` / `POST /api/i18n/import` endpoints to move a group (or all groups) for a locale to and from CSV or JSON `key,value` rows. Imports run through the existing safe write path (per-group lock, backups, optimistic-hash conflict) as a batch of `set` ops.
- **Machine-translation seam**: a `Kurt\Modules\I18n\Contracts\Translator` contract with a `NullTranslator` default (throws until configured), bound via `config('i18n.translator')`, plus a `POST /api/i18n/translate-missing` action that fills a locale's missing keys from a reference through the configured translator and the safe write path. The consumer ships the real DeepL/Google/LLM implementation.
- `TranslationsChanged` domain event, dispatched after a batch actually changes a file (with the file type, group, changed locales, applied ops, and actor when resolvable) for audit/webhook/cache extensions.
- README sections documenting the JSON API contract (endpoints, `baseHashes` + `ops` body, `409` conflict shape), the concurrency semantics, the missing-key report, the import/export formats, and how to wire a `Translator`.

### Changed

- **Breaking — HTTP surface is now safe-by-default and gated.** The always-on `/i18n/api/*` routes (`web` middleware + `viewI18n` gate) are replaced by the Core API kit. Nothing is registered until `I18N_HTTP_MODE` is set to `api` (JSON API) or `ui` (API + the bundled UI shell); `headless` is the default. Every endpoint — reads and writes alike — now requires authentication (`config('i18n.http.auth_middleware')`) plus the `i18n.manageTranslations` gate, and lives under `api/i18n` instead of `i18n/api`. Response bodies are wrapped in the `{ data, meta }` envelope, and the `409` conflict payload moves the stale locales under `errors.locales`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- **BREAKING** — requires Laravel 13, PHP 8.4 and `ozankurt/laravel-modules-core` `^2.0`. Laravel 12 and PHP 8.3 are no longer supported. `spatie/laravel-package-tools` moves to `^1.93`, the first release that supports Laravel 13. The test suite moves to Pest 5 / Testbench 11, and CI now runs PHP 8.4 + Laravel 13.
- `I18nServiceProvider::moduleManifest()` narrows its return type from `?ModuleManifest` to `ModuleManifest`, matching what it actually returns.

### Fixed

- **Concurrent-save lost update**: the optimistic-hash check now runs inside an exclusive per-group file lock, held across the whole read-modify-write. Two saves from the same base hashes can no longer both succeed and silently clobber one another; the second gets a `409`.
- **Multi-locale batches are now atomic**: all locales are staged and verified before any file is swapped in, and a mid-batch failure rolls the already-written files back, so a batch never lands half-applied.

### Changed

- The catalog scan is memoized per manager instance (invalidated on write), so a single request no longer rescans the translation tree multiple times.

## [2.2.0] - 2026-06-04

### Added

- **Vendor packages mode**: a third workspace for namespaced package translations in `lang/vendor/{package}/{locale}/{group}.php`. Pick a package, then a group, and edit it as `package::group` with the locale as the proper target axis.
- **Folder browser** for PHP groups — nested groups (and vendor packages) are navigated as collapsible folders instead of one flat list.
- **Browser history**: views are hash-routed (`#g`, `#g/<folder>`, `#e/php/<group>`, `#v`, `#e/v/<package>/<group>`), so back/forward, refresh, and bookmarks work; clickable breadcrumb path.

### Fixed

- `lang/vendor` was incorrectly scanned as a locale, surfacing bogus groups like `firewall/en/notifications` with the locale baked into the path. It is now recognised as namespaced package translations (see Vendor packages mode).

### Changed

- The catalog API response gains a `vendor` section listing each package's groups and locales.

## [2.1.0] - 2026-06-03

### Changed

- Redesigned the translation UI ("i18n Studio"): a custom dark "command-deck" theme (glassmorphism, neon accents) replacing the Tailwind-utility interface.
- The grid gains a live translation-progress bar, a "next missing" jump, per-reference copy-to-target, keyboard shortcuts (`/` to search, `Ctrl`/`⌘`+`S` to save), and full keyboard/ARIA accessibility.

### Added

- Standalone, fully interactive UI reference at `template/index.html`.

### Upgrade

- Re-publish the UI assets to pick up the new look:
  `php artisan vendor:publish --tag="i18n-assets" --force`

## [2.0.0] - 2026-06-03

### Added

- Initial release.
- Web UI (Tailwind 4 + vanilla JS, zero consumer build step) for managing Laravel translation files.
- JSON mode for flat `lang/{locale}.json` files.
- PHP array mode for `lang/{locale}/**.php` group files, including deeply nested keys via dot-paths.
- Locale-comparison grid with a choosable target locale and toggleable reference locales.
- Safe, atomic file writes with read-back self-verification and optional timestamped backups.
- `viewI18n` authorization gate + environment restriction (non-production by default).
- Path-allowlisted file access (traversal rejected) and escaped-string-literal-only PHP emission.
- Optimistic concurrency control (per-file hash, HTTP 409 on conflict).
