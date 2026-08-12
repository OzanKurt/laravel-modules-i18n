# i18n source scanner and scan report - design

**Date:** 2026-08-01
**Status:** approved, ready for implementation planning
**Scope:** Phase A of three. Phases B and C get their own specs.

## Why this exists

`laravel-modules-i18n` answers one question well: "which locales have fallen
behind?" `MissingKeyReport` walks every group and lists keys present in a
reference locale but absent from a target locale.

It cannot answer a different question: "does my code use a key I never
defined?" There is no source-code scanning at all, so a key referenced by
`__()` but missing from every language file is invisible.

Comparison with `arm092/laravel-translation` made the gap concrete. The two
packages work on different axes: theirs syncs code against files, ours measures
completeness across locales. The axes are complementary, not overlapping, and
ours is missing one of them entirely.

## Decomposition

The full wish list is three separate deliverables. This spec covers **A only**.

| | Deliverable | Depends on |
|---|---|---|
| **A** | Source scanner plus the four-category scan report | nothing |
| **B** | Artisan commands, CI mode, protected locales, deterministic ordering, import conflict policy | A |
| **C** | Quality dashboard UI | A and B |

A is designed and built first because it is the engine. If its output shape is
wrong, B and C are wasted work.

## Decisions taken

| Decision | Choice |
|---|---|
| Key resolution | Catalog-driven; unresolvable keys reported separately rather than guessed |
| Unused-key noise | Default ignore list for framework groups and vendor, overridable in config |
| Environment gate | `enabled_environments` guards HTTP only; CLI (phase B) is unaffected |
| Scanner technique | PHP tokenizer, with Blade compiled to PHP first |
| A writes anything? | No. A is read-only |

## Architecture

Four new classes under `src/Support/`, matching the package's existing layout.

```
SourceScanner      walks the source tree, compiles Blade, tokenises, produces
                   raw findings
  -> Usage[]       { key, file, line, method, isLiteral }

KeyResolver        places each finding against the catalogue
  -> json | group | ambiguous

ScanReport         assembles the four categories
ScanCache          fingerprint cache
```

Dependencies run one way. `SourceScanner` reads disk and knows nothing about
translations. `KeyResolver` takes the existing `TranslationCatalog` and decides.
`ScanReport` combines the two. All three are pure apart from
`Illuminate\Filesystem`.

**Why not extend `LocaleScanner`.** That class scans `lang/`; this one scans
`app/` and `resources/`. Same word, entirely different input and
responsibility. Merging them would blur both.

**`MissingKeyReport` is left alone.** It measures cross-locale completeness and
works correctly. The new report measures the code-to-file axis. They stay
separate; phase C's dashboard can show them side by side.

**A is read-only.** The worst outcome of a scanning bug is a wrong report, never
a damaged file.

### Out of scope for A

Artisan commands and CI mode, the dashboard UI, protected locales, the
deterministic ordering command, and the import conflict policy. All belong to B
or C.

## Key resolution

For each literal found, in order:

```
"package::messages.key"  -> vendor namespace; does the catalogue hold that package and group?
"auth.failed"            -> split on the first dot; is "auth" a known PHP group?
                            yes -> group key
                            no  -> does the JSON store hold this exact key?
                                   yes -> JSON
                                   no  -> AMBIGUOUS
"Welcome back"           -> no dot, so it cannot be a group key -> JSON
```

A dotless key can never resolve to a group, because Laravel requires
`group.item`, so that branch is certain.

## The four categories

| Category | Meaning |
|---|---|
| `missing` | Resolved key used in code, with no value in the target locale |
| `unused` | Key stored in a file, with no literal use anywhere in code |
| `dynamic` | Call site is not a literal (`__($key)`, `__("a.$b")`); the key could not be read |
| `ambiguous` | Literal was read but fits no store; where it should be written is unknown |

Splitting `dynamic` from `ambiguous` is deliberate. The first means "we do not
know what it is looking for"; the second means "we know what it is looking for
but not where it belongs". The developer's next action differs: convert the call
to a fixed key, versus create the group. Merging them would make both
unactionable.

`missing` is per-locale. The other three are locale-independent, because code
keys do not vary by locale.

### Safety rail on `unused`

`unused` has a silent failure mode: if `scan.paths` is misconfigured the scanner
finds zero usages and declares **every** stored key unused. Anyone acting on
that report without reading it loses their whole catalogue.

Therefore: **when a scan finds zero literal usages, `unused` is not produced at
all**; a configuration warning is returned instead. Zero usages is impossible in
a real project and always indicates misconfiguration.

Default ignore list: the `validation`, `passwords`, `auth` and `pagination`
groups, plus everything under `lang/vendor/`. These are consumed by the
framework or by packages and never appear as literal `__()` calls in
application code, so without this the report opens with hundreds of false
positives and nobody reads it. Overridable through `scan.ignored_groups`.

### What the two ignore settings do, and to which categories

They are not interchangeable, and both affect **`unused` only**:

- `ignored_groups` suppresses whole groups, for keys the framework or a package
  consumes rather than application code.
- `ignored_keys` suppresses individual keys, for the case where an application
  genuinely builds a key at runtime and the developer has accepted that the
  scanner cannot see it.

Neither suppresses `missing`. A key written in code but absent from the files is
a real gap even inside an ignored group: `__('validation.custom.email')` with no
such entry is still broken, and hiding it would defeat the point. The ignore
settings exist to stop the reverse direction (stored keys that no literal call
reaches) from drowning the report.

`dynamic` and `ambiguous` are unaffected by either setting; they describe call
sites, not stored keys.

## Blade must be compiled before tokenising

This is the one finding that shaped the architecture.

In `.blade.php` files most translation calls look like:

```blade
{{ __('Welcome back') }}
@lang('auth.failed')
```

To PHP's tokeniser both are `T_INLINE_HTML`, plain text. Without a `<?php`
opening, `token_get_all()` never sees the `__()` call inside `{{ }}`. Handling
only `@lang` with a separate pass would miss the far more common form.

The fix is to compile first:

```php
$php = Blade::compileString($source);   // {{ __('x') }}  becomes  <?php echo e(__('x')); ?>
$usages = $this->tokenize($php);
```

Compiled output is real PHP, so the tokeniser's accuracy returns in full:
comments, strings containing call-like text, and nested quotes all separate
correctly. Laravel's own compiler resolves every directive form, so the package
does not maintain a hand-written directive list.

Cost: a dependency on `illuminate/view`. In practice free, since every
application installing this package already has `laravel/framework`; declaring
it in `composer.json` is honesty rather than weight.

**Line numbers:** compilation can shift lines, so findings must be reported
against the source line, not the compiled one. Blade's compiler tries to
preserve line counts but does not guarantee it. Implementation must verify this
and map back to the source where it does not hold.

## Configuration

One new `scan` block in `config/i18n.php`:

```php
'scan' => [
    'paths'          => [app_path(), resource_path()],
    'excluded_paths' => [base_path('vendor'), storage_path()],
    'methods'        => ['__', 'trans', 'trans_choice'],
    'ignored_keys'   => [],
    'ignored_groups' => ['validation', 'passwords', 'auth', 'pagination'],
    'cache'          => true,
    'cache_path'     => null,   // null resolves to storage/framework/cache/i18n-scan.json
],
```

`Lang::get`, `Lang::choice` and `app('translator')->get` are always recognised.
`methods` exists to add an application's own wrappers on top.

## Caching

Per file: path plus `filemtime` plus size. An unchanged file's findings come
from cache.

**The cache must also be keyed on configuration.** Adding a function to
`methods` leaves every file's fingerprint identical, so without this the new
function is never scanned and the result is silently wrong. The cache header
therefore stores a hash of the `scan` block, and any change to that block
invalidates the cache wholesale.

Symlinks are not followed, and files resolving outside the configured roots are
rejected, matching the rule `LangPaths` already applies.

## API surface

One endpoint, following the existing pattern (`src/Http/Controllers/Api/`,
extending `ApiController`, under the `api/i18n` prefix):

```
GET api/i18n/scan?locale=tr&refresh=1
```

It returns all four categories from a single scan. Without `locale`, `missing`
is computed for every known locale; the other three are locale-independent
anyway. `refresh` bypasses the cache.

One endpoint rather than four, because all four categories come from the same
scan and splitting them would repeat the work.

## Error handling

The governing principle: **a scan must not die on partial failure.** Producing a
report is a read operation, and getting no report because of one broken file is
worse than getting a report with a gap in it.

- **Unparseable file** (broken PHP, invalid Blade): skipped, and listed in the
  response under `warnings` with its path and reason. The scan continues.
- **Zero literal usages**: `unused` is withheld and a configuration warning is
  returned instead.
- **Path escaping a configured root**: the existing `TranslationPathException`
  is thrown. That is a configuration fault and must be loud.
- **Unreadable cache file**: ignored silently and rebuilt. The cache is an
  optimisation, not a source of truth.

Data faults are loud; environment faults are resilient.

## Testing

The most valuable tests are the ones proving the tokeniser earns its complexity
over a regex. If that is the justification for the approach, it must be pinned:

```php
// none of these may be reported
// __('example inside a comment')
$s = "text that mentions __('thing')";
$doc = '/** @see __(\'x\') */';

// these must be reported
__('real.key');
{{ __('from Blade') }}
@lang('directive');
```

Alongside that:

- **`KeyResolver`** against a synthetic catalogue, covering all four branches:
  vendor namespace, existing group, JSON fallback, and ambiguous.
- **Cache invalidation**: changing `scan.methods` must produce a new result even
  though no file changed. This is the subtle trap from the caching section and
  fails silently, so it needs an explicit test.
- **Zero-usage rail**: with misconfigured `scan.paths`, `unused` comes back
  empty and a warning is present.
- **Feature**: the endpoint itself, following the existing API test patterns.

Fixtures live in `tests/Fixtures/scan-app/`: a few `.php` files, a few
`.blade.php` files, and one deliberately broken file.

## Family standards

PHP `^8.4`, Laravel `^13.0`, Pest 5, PHPStan level 8 with no suppressions, Pint,
the existing `tests.yml` CI workflow, and a README section describing the new
endpoint and config block.
