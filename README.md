# laravel-modules-i18n

A self-hosted **translation-file manager** for Laravel. It ships its own UI (Tailwind 4 + vanilla
JS — no build step required in your app) for editing both **JSON** and **PHP array** language files
on disk, including deeply nested PHP keys like `'foo' => ['bar' => 'baz']`.

Part of the [KurtModules](https://github.com/ozankurt) family. Requires
[`ozankurt/laravel-modules-core`](https://github.com/OzanKurt/KurtModules-Core).

## Features

- **Two file types, one workspace.** Flat `lang/{locale}.json` files and nested `lang/{locale}/**.php`
  group files. You pick the type first, then (for PHP) the file/group, then edit.
- **Locale-comparison grid.** Choose a target locale to fill in and toggle any number of reference
  locales for context — translate `users.title.icon_tooltip` into French while reading the EN and TR
  values side by side.
- **Safe writes.** Atomic writes with read-back self-verification, optional timestamped backups, and
  PHP files emitted as escaped string literals only (your input is never written as code).
- **Locked down by default.** Gated by the `viewI18n` authorization gate and restricted to
  non-production environments unless you opt in. Only files inside the configured lang root are ever
  touched.

## Requirements

- PHP 8.4+
- Laravel 13.x
- `ozankurt/laravel-modules-core` v2.x

## Installation

```bash
composer require ozankurt/laravel-modules-i18n
```

Publish the config and the prebuilt UI assets:

```bash
php artisan vendor:publish --tag="i18n-config"
php artisan vendor:publish --tag="i18n-assets"
```

## Access control

Translation management writes files on your server and is treated as an **admin** surface, so it is
**safe by default**: nothing is registered until you opt in.

**Enable the REST API** by setting the HTTP mode (Core API-kit convention):

```dotenv
# .env
I18N_HTTP_MODE=api   # headless (default) | api | ui
```

- `headless` (default) — no routes at all.
- `api` — the JSON REST API under `config('i18n.http.prefix')` (default `api/i18n`).
- `ui` — everything in `api` **plus** the bundled translation-manager UI shell under
  `config('i18n.route.prefix')` (default `i18n`).

Every REST endpoint (reads and writes alike) runs behind two layers:

1. `config('i18n.http.auth_middleware')` (default `['auth']`) — the request must be authenticated.
2. The `i18n.manageTranslations` gate — granted automatically in any environment listed in
   `config('i18n.enabled_environments')` (default `['local']`); everywhere else you override it,
   typically in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('i18n.manageTranslations', function ($user) {
    return in_array($user->email, ['you@example.com'], true);
});
```

The API routes use the `api` middleware group (stateless). To drive the bundled `ui`-mode UI (whose
SPA authenticates with the session cookie), add `web` to `config('i18n.http.middleware')` — or point
`auth_middleware` at a guard that reads your session — so the API shares the UI's session context.
The UI shell itself keeps the legacy `web` + environment/`viewI18n` guard.

## Usage

In `ui` mode, visit `/i18n` (configurable via `config('i18n.route.prefix')`). Pick **JSON** or
**PHP array**; for PHP choose a group (file); then edit in the grid:

- select a **target** locale and any **reference** locales,
- filter to **missing only**, search keys, copy a reference value into the target,
- add / rename / delete keys, add a new locale,
- **Save** writes only the files that changed.

By default the manager reads and writes the application's `lang_path()`. Point it elsewhere with
`config('i18n.paths.root')`.

## JSON API

Set `I18N_HTTP_MODE=api` (or `ui`) to mount the REST API under `config('i18n.http.prefix')` (default
`api/i18n`). It is built on the Core API kit: successful responses are wrapped in a
`{ "data": …, "meta": … }` envelope (`meta` omitted when empty), and errors are
`{ "message": …, "errors": … }`. Every route is authenticated and gated (see **Access control**) and
throttled by the `i18n-api` limiter (`config('i18n.http.rate_limit')`, default `60,1`). All request
and response bodies are JSON.

| Method & path                     | Purpose                                        |
| --------------------------------- | ---------------------------------------------- |
| `GET /api/i18n/catalog`           | List locales, JSON files, PHP groups, vendor packages. |
| `GET /api/i18n/groups`            | List every translation group as `{type, group}` pairs. |
| `GET /api/i18n/locales`           | List the known locales.                        |
| `GET /api/i18n/json`              | Read the JSON translation grid.                |
| `PATCH /api/i18n/json`            | Apply a batch of edits to JSON files.          |
| `GET /api/i18n/php/{group}`       | Read a PHP group grid (`{group}` may be nested, e.g. `admin/users`, or namespaced, e.g. `firewall::notifications`). |
| `PATCH /api/i18n/php/{group}`     | Apply a batch of edits to a PHP group.         |
| `GET /api/i18n/translations`      | Show one key's value across locales (`?type=&group=&key=`). |
| `PUT /api/i18n/translations`      | Set a single key for one locale.               |
| `DELETE /api/i18n/translations`   | Delete a single key from every loaded locale.  |
| `POST /api/i18n/locales`          | Create a new empty locale file.                |
| `GET /api/i18n/report/missing`    | Cross-group missing-key report for a reference locale. |
| `GET /api/i18n/export`            | Export a locale (one group or all) to CSV/JSON.|
| `POST /api/i18n/import`           | Import CSV/JSON `key,value` rows into a group.  |
| `POST /api/i18n/translate-missing`| Fill a locale's missing keys via the configured translator. |

### Reading a grid

`GET /api/i18n/json?locales=en,tr` (omit `locales` to load every known locale) returns:

```json
{
  "data": {
    "keys": ["greeting"],
    "rows": { "greeting": { "en": "Hi", "tr": "Selam" } },
    "hashes": { "en": "b1c9…", "tr": "4af0…" }
  }
}
```

`hashes` is a `locale => SHA-1` map of each file's current on-disk contents (`null` when the file
does not exist yet). You send these back unchanged as the `baseHashes` of a later edit; they are how
the server detects that a file changed under you.

### Applying edits

`PATCH /api/i18n/json` (or `/api/i18n/php/{group}`) takes the loaded `baseHashes` plus an ordered
list of `ops`:

```json
{
  "baseHashes": { "en": "b1c9…", "tr": "4af0…" },
  "ops": [
    { "op": "set", "locale": "en", "key": "greeting", "value": "Hello" },
    { "op": "rename", "from": "greeting", "to": "welcome" },
    { "op": "delete", "key": "obsolete" }
  ]
}
```

- `set` writes one cell (`locale` + `key` + `value`); the locale must be one of the loaded `baseHashes`.
- `rename` moves `from` to `to` in every loaded locale (a no-op for a non-leaf PHP key, so a subtree is never collapsed).
- `delete` removes `key` from every loaded locale.

On success (`200 OK`) the response mirrors a fresh read of the affected files:

```json
{ "data": { "changed": ["en"], "hashes": { "en": "77de…", "tr": "4af0…" } } }
```

`changed` lists only the locales whose files were actually rewritten (a batch that resolves to the
current contents changes nothing and writes nothing). Use the returned `hashes` as the `baseHashes`
for your next edit.

### Single-key writes

For one-cell changes without assembling a batch, `PUT /api/i18n/translations` sets a key and
`DELETE /api/i18n/translations` removes it — both through the same safe write path (lock + backup +
optimistic hash), returning the same `{ "data": { "changed", "hashes" } }` envelope:

```json
{ "type": "json", "locale": "en", "key": "greeting", "value": "Hello", "baseHashes": { "en": "b1c9…" } }
```

`GET /api/i18n/translations?type=json&key=greeting` reads a single key's value per locale plus the
current hashes and an `exists` flag.

### Conflicts (`409`)

When any file's current hash no longer matches the `baseHashes` you sent, the whole batch is rejected
and nothing is written:

```json
{ "message": "conflict", "errors": { "locales": ["en"] } }
```

`errors.locales` names the files that changed underneath you. Re-read the grid (to get fresh values
and hashes), reapply your edits, and retry. Invalid input (bad locale/group, unknown op, out-of-root
path) returns `422` with `{ "message": "…", "errors": … }` instead.

### Concurrency semantics

Saves are serialized per group with an exclusive file lock, and the optimistic hash check runs
**inside** that lock immediately before writing. Two concurrent `PATCH`es that started from the same
`baseHashes` can therefore never both succeed: the first wins, and the second sees the file it just
changed and gets a `409` — there is no silent last-writer-wins. A multi-locale batch is atomic: all
locales are staged and verified first, then swapped in together, and any failure rolls the batch back
so the files are never left half-applied.

### Extending: the `TranslationsChanged` event

After a batch actually changes at least one file, the manager dispatches
`Kurt\Modules\I18n\Events\TranslationsChanged` with the file `type`, the `group` (or `null` for
JSON), the `changedLocales`, the applied `ops`, and the `actor` (the authenticated user, when
resolvable). Listen for it to keep an audit log, fire a webhook, bust a translation cache, or trigger
a redeploy. It does not fire for a no-op batch.

## Cross-group missing-key report

`GET /api/i18n/report/missing?reference=en` answers "what still needs translating?" across **every**
group at once — the JSON pseudo-group plus every project and vendor PHP group — instead of one open
group at a time. Given a reference locale it lists, per target locale, the keys the reference defines
that are absent from that locale's copy of each group.

- `reference` (required) — the locale whose keys are the source of truth.
- `locales` (optional, `a,b,c`) — restrict the targets; defaults to every known locale but the reference.

```json
{
  "data": {
    "reference": "en",
    "locales": ["de", "tr"],
    "groups": [
      { "type": "json", "group": null,    "missing": { "tr": ["bye"] } },
      { "type": "php",  "group": "users", "missing": { "de": ["title.icon"], "tr": ["title.icon"] } }
    ]
  }
}
```

Only gaps are reported: a locale appears under a group only when it is missing at least one key, and a
group is omitted entirely when every target is complete. A group whose file is absent for a locale
surfaces as **all** the reference keys being missing for it. The same report is available in PHP via
`Kurt\Modules\I18n\Support\MissingKeyReport::generate($reference, $targets = null)`.

## Import / export

Export and import a locale's translations as flat `key,value` rows (nested PHP keys are dot-paths,
e.g. `title.icon`), in **CSV** or **JSON**.

### Export

`GET /api/i18n/export?locale=en&format=json` returns a downloadable file (`Content-Disposition:
attachment`). Being a file download, its body is the raw `key,value` rows — not the `data` envelope.

- `locale` (required), `format` (`csv` | `json`, default `json`).
- `type` (`json` | `php`) + `group` (required for `php`) — export a **single** group as `key,value` rows.
- Omit `type` — export **all** groups for the locale; each row also carries `type` and `group` columns
  so the flat list stays unambiguous.

```json
[ { "key": "greeting", "value": "Hi" }, { "key": "title.icon", "value": "Manage" } ]
```

The CSV form is the same rows with a `key,value` header (RFC 4180 quoting).

### Import

`POST /api/i18n/import` applies rows to one group + locale:

```json
{
  "type": "php",
  "group": "users",
  "locale": "de",
  "format": "csv",
  "content": "key,value\ntitle.icon,Verwalten\n",
  "baseHashes": { "de": "5f2a…" }
}
```

The JSON `content` accepts either `[{ "key": …, "value": … }]` rows or a flat `{ "key": "value" }`
object of scalars; CSV needs `key` and `value` columns in any order (extra columns are ignored). An
import is **not** a raw overwrite: it is turned into a batch of `set` operations and applied through
the same safe write path as edits — the exclusive per-group lock, timestamped backups, and the
optimistic-hash conflict check all apply. Send the target locale's current hash as `baseHashes`
(read it from a grid first); a stale hash returns `409`, and a malformed payload returns `422` with
nothing written. `Kurt\Modules\I18n\Support\TranslationExporter` and `TranslationImporter` expose the
same behaviour in PHP.

## Machine translation

The package ships a **seam**, not a provider. `POST /api/i18n/translate-missing` fills a target
locale's missing keys in one group by machine-translating the reference values and writing the
results through the safe write path:

```json
{ "type": "json", "reference": "en", "locale": "tr", "baseHashes": { "en": "…", "tr": "…" } }
```

It responds (inside the `data` envelope) with the usual `changed` / `hashes` plus a `translated` list
of the keys it filled. Only keys the target lacks are translated; existing values are left untouched.

Translation goes through the `Kurt\Modules\I18n\Contracts\Translator` contract:

```php
interface Translator
{
    public function translate(string $text, string $from, string $to): string;
}
```

The default binding is `NullTranslator`, which **throws** (`TranslatorNotConfiguredException`, surfaced
as `501`) rather than silently writing the untranslated source. Wire your own DeepL/Google/LLM-backed
implementation via config:

```php
// config/i18n.php
'translator' => \App\Translation\DeepLTranslator::class,
```

```php
namespace App\Translation;

use Kurt\Modules\I18n\Contracts\Translator;

final class DeepLTranslator implements Translator
{
    public function translate(string $text, string $from, string $to): string
    {
        // call your provider and return the translated string
    }
}
```

Any dependencies your implementation type-hints are resolved from the container.

## Configuration

See [`config/i18n.php`](config/i18n.php) — the `http` block (mode / prefix / middleware /
auth_middleware / rate_limit for the REST API), environment allowlist, UI route prefix/middleware,
lang root, backups, and an optional explicit locale → label map.

## Security

This is a filesystem-writing admin tool. Review [SECURITY.md](SECURITY.md) before exposing it. It is
headless (nothing registered) until you set `I18N_HTTP_MODE`, and every endpoint requires
authentication plus the `i18n.manageTranslations` gate. Never serve it publicly without a restrictive
gate override in production (and, for the `ui` shell, the `viewI18n` gate).

## Development

The UI assets are prebuilt and committed under `resources/dist/`. To rebuild after changing
`resources/css` or `resources/js`:

```bash
npm install
npm run build
```

```bash
composer test     # pest
composer lint     # pint --test
composer stan     # phpstan
```

## License

MIT © Ozan Kurt
