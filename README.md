# laravel-modules-i18n

[![tests](https://github.com/OzanKurt/laravel-modules-i18n/actions/workflows/tests.yml/badge.svg)](https://github.com/OzanKurt/laravel-modules-i18n/actions/workflows/tests.yml)

A self-hosted **translation-file manager** for Laravel. It ships its own UI (Tailwind 4 + vanilla
JS — no build step required in your app) for editing both **JSON** and **PHP array** language files
on disk, including deeply nested PHP keys like `'foo' => ['bar' => 'baz']`.

Part of the [KurtModules](https://github.com/ozankurt) family. Requires
[`ozankurt/laravel-modules-core`](https://github.com/OzanKurt/laravel-modules-core).

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

- PHP `^8.4`
- Laravel `^13.0`
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
| `GET /api/i18n/report/scan`       | Source-scan report: keys used in code vs. keys defined in files. |
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

## Source scanning

`GET /api/i18n/report/scan` answers a different question than the missing-key report above: not "what is
one locale short of another" but "what does the code actually call, and does the catalogue line up
with it." It walks the configured source paths for translation-function call sites and cross-checks
every key it finds against the JSON and PHP files on disk, in one pass.

- `locales` (optional, `a,b,c`, same convention as every other endpoint): the locales to check for
  `missing` keys. Omitted or blank, it falls back to every locale on disk. This is deliberate: an
  empty filter carries no information about which locales you meant, and answering it literally would
  report an empty `missing` for a catalogue that may be full of gaps.
- `refresh` (optional, boolean): flush the scan cache before running, so a truthy value (`1`, `true`)
  forces every file to be re-read instead of reusing a cached result.

```json
{
  "data": {
    "locales": ["en", "tr"],
    "missing": {
      "tr": [
        { "key": "users.title",      "store": "group",     "group": "users", "package": null },
        { "key": "some.orphan.key",  "store": "ambiguous", "group": null,    "package": null }
      ]
    },
    "unused": ["old.banner"],
    "dynamic": [{ "file": "app/Http/Controllers/HomeController.php", "line": 42, "method": "__" }],
    "ambiguous": [{ "key": "some.orphan.key", "file": "resources/views/home.blade.php", "line": 7 }],
    "warnings": []
  }
}
```

The four categories answer different questions, so they are never merged into one list:

- **`missing`**: keyed by locale (a locale is present only when it is missing at least one key), the
  keys code calls literally that have no value in that locale's files. Each entry is an object, not a
  bare string, carrying where the key *would* go: `store` (`json` | `group` | `vendor` | `ambiguous`),
  plus `group` and `package` when the resolver could name them (`null` otherwise). A `store` of
  `ambiguous` means the key belongs nowhere we can name, so the entry tells you on its own that the
  key is unplaceable, without cross-referencing the `ambiguous` list by string.
- **`unused`**: keys the catalogue defines that no scanned call site references. Locale-independent, so
  a key only `en` has to lose is still "used" for every locale's purposes. Read it as advice, never as
  a delete list: **only `*.php` files are scanned** (including `*.blade.php`, which is compiled first),
  so a key your front end calls from `resources/js/**/*.vue` or `*.js` through a translation shim has
  no PHP call site and is reported unused. The PHP side of such an app still yields plenty of literals,
  so nothing else in the report warns you about it. It is also withheld entirely (an empty list plus a
  `warnings` entry) whenever the scan cannot vouch for the whole walk: see **When `unused` is
  withheld** below.
- **`dynamic`**: call sites whose first argument was not a plain string literal (a variable, a
  concatenation, an interpolated string), so the key could not be read at all. Nothing is guessed;
  these are left for a human to check by hand.
- **`ambiguous`**: a literal key that *was* read but matches no known store, meaning not a JSON key,
  not `group.item` for any known PHP group, and not `package::group.item` for any known vendor group.
  It is kept separate from `dynamic` because the difference matters to what you do next: a dynamic
  call needs a human to read the code, an ambiguous key needs a human to decide where it belongs.

`dynamic` and `ambiguous` are kept apart for the same reason: one means "could not be read," the other
means "was read but fits nowhere," so conflating them would leave both unactionable.

Every `file` in `dynamic`, `ambiguous` and `warnings` is written relative to the application root
(`base_path()`), as in the example above. A file that genuinely lives outside the root keeps its
absolute path, since a relative one would only have to climb back out.

A key can appear in **both** `ambiguous` and `missing`. An ambiguous key is by definition not found in
any store, so every requested locale reports it missing too, with `"store": "ambiguous"` on the
`missing` entry. Both are true of it at once, and together they mean the key is *unplaceable*, not
merely untranslated. Do not auto-create it in a file, since there is no evidence which file it belongs
in. `ambiguous` still carries the file and line, which `missing` does not: it is keyed by locale, and a
key's call sites have nothing to do with which locale lacks it.

`config('i18n.scan.ignored_groups')` and `config('i18n.scan.ignored_keys')` suppress `unused` only.
A key named by either config, or belonging to an ignored group, is never reported unused even if
nothing calls it. Neither ever hides a key from `missing`: a key your code actually calls is reported
missing regardless of any ignore list, because "the developer chose not to be told this key is
unused" is not the same claim as "this key does not need translating."

A vendor namespaced key (`package::group.item`) is withheld from `unused` unconditionally, on top of
the two settings above and regardless of what they are set to (even both empty). The reasoning is the
same one behind `ignored_groups`, just not optional: a vendor string belongs to the package that
ships it, so this application is not the right place to judge it unused. As with the two configured
lists, this never hides a vendor key from `missing`; only `unused` is affected.

### When `unused` is withheld

`unused` is the only category that is advice to *delete* something, so it is published only when the
scan can vouch for the whole walk. Two conditions withhold it, each returning an empty list plus an
explanatory entry in `warnings`:

1. **Zero literal call sites were found.** That is always a misconfiguration (most likely
   `i18n.scan.paths` pointing somewhere with no source in it), never a legitimate "nothing is used."
   Publishing an `unused` list built from zero usages would read as "delete your whole catalogue," so
   the warning points at `i18n.scan.paths` instead.
2. **Any file failed to scan**, i.e. `warnings` is not empty. A key called only from a file that could
   not be read or parsed is indistinguishable from a key nothing calls, so it would be reported unused
   and a consumer acting on the report would delete a key the application uses at runtime. It is
   all-or-nothing for the same reason the walk is: there is no way to tell which of the surviving
   "unused" keys the failed files would have vouched for.

The other categories are unaffected by either: `missing`, `dynamic` and `ambiguous` are still reported
from whatever the scan did see.

Published vendor views are scanned too. The default `i18n.scan.paths` includes `resource_path()`,
which covers `resources/views/vendor/**` once a package's views are published there, so a translation
call in a published vendor view is picked up like any other call site. Its `package::group.item` keys
are reported `missing` until the consuming application publishes the matching lang files for that
vendor namespace.

The full `scan` config block, with its defaults:

```php
'scan' => [
    'paths' => null,            // null resolves to [app_path(), resource_path()]
    'excluded_paths' => null,   // null resolves to [base_path('vendor'), storage_path()]
    'methods' => ['__', 'trans', 'trans_choice'],
    'ignored_keys' => [],
    'ignored_groups' => ['validation', 'passwords', 'auth', 'pagination'],
    'cache' => true,
    'cache_path' => null,       // null resolves to storage/framework/cache/i18n-scan.json
],
```

- `paths`: the roots walked for call sites. `null` resolves to `[app_path(), resource_path()]`. Only
  `*.php` files under those roots are read; every other extension is skipped.
- `excluded_paths`: roots skipped entirely (symlinks under a scanned root are never followed either,
  regardless of this list). `null` resolves to `[base_path('vendor'), storage_path()]`.
- `methods`: extra function/method names recognised as translation calls, on top of the built-in
  `__`, `trans` and `trans_choice`. `get` and `choice` are recognised too, but **only** on a translator
  receiver (`Lang::get(…)`, `app('translator')->choice(…)`), and listing either here does not change
  that: they are far too common on other objects for a bare `$request->get('x')` or `Cache::get('x')`
  to be a translation call, and no configuration can say otherwise.
- `ignored_keys` / `ignored_groups`: see above, `unused` only, never `missing`.
- `cache`: when `true`, scanned files are fingerprinted (path + mtime + size) so an unchanged file is
  not re-tokenised on the next scan; the whole cache also keys itself on a hash of this `scan` config
  block, so changing any of the settings above invalidates it automatically.
- `cache_path`: where the cache file is written. `null` resolves to
  `storage_path('framework/cache/i18n-scan.json')`.

The same report is available in PHP via `Kurt\Modules\I18n\Support\ScanReport::generate($locales =
null)`, and the cache can be cleared directly with `Kurt\Modules\I18n\Support\ScanCache::flush()`.

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

## Testing

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pest
```

CI runs the same checks on every push and pull request
(`.github/workflows/tests.yml`), against PHP 8.4 / Laravel 13. Static analysis
is held at **PHPStan level 8**; the suite runs on **Pest 5**.

The UI assets are prebuilt and committed under `resources/dist/`. To rebuild after changing
`resources/css` or `resources/js`:

## License

MIT © Ozan Kurt
