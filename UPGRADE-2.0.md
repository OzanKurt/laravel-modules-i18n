# Upgrade Guide — 2.0

**Initial release.** There is no previous version to upgrade from.

`ozankurt/laravel-modules-i18n` joins the KurtModules family at `v2.0.0` to align with the shared
v2 line. Install it with:

```bash
composer require ozankurt/laravel-modules-i18n
```

See the [README](README.md) for setup, the `viewI18n` gate, and publishing the UI assets.

## Requirements

`v2.0.0` targets **PHP 8.4** and **Laravel 13** (`illuminate/*` `^13.0`), with
`ozankurt/laravel-modules-core` `^2.0`. Laravel 12 and PHP 8.3 are not supported.

## REST API migrated onto the Core API kit

The HTTP layer now uses the shared Core API kit (requires `ozankurt/laravel-modules-core` `^2.0`).
This is a **breaking change** to how routes are registered and secured — the previous always-on
`/i18n/api/*` routes have been replaced.

**What changed**

- **Safe by default.** No routes register until you opt in. Set the HTTP mode in `.env`:

  ```dotenv
  I18N_HTTP_MODE=api   # headless (default) | api | ui
  ```

  - `api` mounts the JSON REST API.
  - `ui` mounts the API **and** the bundled translation-manager UI shell (previously always-on in
    your `enabled_environments`).
  - `headless` (default) registers nothing.

- **New prefix.** Endpoints moved from `i18n/api/*` to `api/i18n/*`
  (configurable via `config('i18n.http.prefix')`).

- **Auth + gate on everything.** Translation management is an admin surface: every endpoint (reads
  and writes) now runs `config('i18n.http.auth_middleware')` (default `['auth']`) **and** the new
  `i18n.manageTranslations` gate. That gate is granted automatically in `enabled_environments`
  (default `['local']`); override it in production:

  ```php
  Gate::define('i18n.manageTranslations', fn ($user) => $user->isAdmin());
  ```

- **Response envelope.** Successful responses are wrapped in `{ "data": … }` (with `{ "meta": … }`
  when present); errors are `{ "message": …, "errors": … }`. The `409` conflict body moved the stale
  locales from a top-level `locales` key to `errors.locales`. Update any API client accordingly.

**Migration steps**

1. `composer update ozankurt/laravel-modules-core` (to `^2.0`).
2. Set `I18N_HTTP_MODE=api` (or `ui`) wherever you want the surface active.
3. Ensure requests are authenticated and define/override the `i18n.manageTranslations` gate for
   non-local environments.
4. Repoint any API clients from `i18n/api/*` to `api/i18n/*` and unwrap the `data` envelope.
