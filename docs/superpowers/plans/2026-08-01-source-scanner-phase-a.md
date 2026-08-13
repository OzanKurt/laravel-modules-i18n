# i18n Source Scanner (Phase A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add source-code scanning to `laravel-modules-i18n` and expose a read-only report of keys used in code but missing from files, keys stored but unused, non-literal call sites, and literals that fit no store.

**Architecture:** Four pure support classes plus one API endpoint. `SourceScanner` walks the configured paths, compiles Blade to PHP, tokenises, and emits `Usage` records. `KeyResolver` places each usage against the existing `TranslationCatalog`. `ScanReport` combines both into four categories. `ScanCache` keys results on file fingerprints plus a hash of the scan config. Nothing in this phase writes to a translation file.

**Tech Stack:** PHP 8.4, Laravel 13, `token_get_all()`, Laravel's Blade compiler, Pest 5, Orchestra Testbench 11, Larastan 3 (level 8), Pint.

## Global Constraints

- PHP `^8.4`; `illuminate/*` `^13.0`; Testbench `^11.0`; Pest `^5.0`; Larastan `^3.0`; Pint `^1.18`.
- Namespace `Kurt\Modules\I18n\`; test namespace `Kurt\Modules\I18n\Tests\`.
- `declare(strict_types=1);` on every PHP file.
- PHPStan level 8 with **no** `ignoreErrors` entries and no `@phpstan-ignore` comments.
- **Phase A is read-only.** No task may write to a translation file.
- Default ignored groups are exactly `validation`, `passwords`, `auth`, `pagination`, plus everything under `lang/vendor/`.
- `ignored_groups` and `ignored_keys` suppress **`unused` only**. They never suppress `missing`, `dynamic` or `ambiguous`.
- A scan finding zero literal usages must withhold `unused` entirely and return a configuration warning instead.
- Repo directory `D:\Code\Projects\laravel-modules-i18n` does not exist; the working directory is `D:\Code\Projects\KurtModules-i18n`.
- The default `php` on this machine is 8.3 and too old. Every PHP command must run through `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`.
- Commit messages must not contain AI attribution or a co-author trailer.
- Never use an em-dash or en-dash in file content or commit messages.

---

## File Structure

| Path | Responsibility |
|---|---|
| `src/Support/Usage.php` | One call site: key, file, line, method, literal flag |
| `src/Support/SourceScanner.php` | Walk paths, compile Blade, tokenise, emit `Usage[]` |
| `src/Support/KeyResolver.php` | Place a key against the catalogue: json / group / ambiguous |
| `src/Support/ScanCache.php` | Fingerprint cache, keyed on files and on scan config |
| `src/Support/ScanReport.php` | Assemble the four categories |
| `src/Http/Controllers/Api/ScanReportController.php` | `GET api/i18n/scan` |
| `config/i18n.php` | New `scan` block |
| `routes/api.php` | One new route |
| `tests/Fixtures/scan-app/` | Sample tree: `.php`, `.blade.php`, one broken file |

---

### Task 1: Config block and the `Usage` value object

**Files:**
- Modify: `config/i18n.php`
- Modify: `composer.json` (add `illuminate/view`)
- Create: `src/Support/Usage.php`
- Test: `tests/Unit/UsageTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `new Usage(string $key, string $file, int $line, string $method, bool $isLiteral)`, all readonly public.
  - Config key `i18n.scan` with sub-keys `paths`, `excluded_paths`, `methods`, `ignored_keys`, `ignored_groups`, `cache`, `cache_path`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/UsageTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\Usage;

it('carries a literal call site', function () {
    $usage = new Usage('auth.failed', '/app/Foo.php', 12, '__', true);

    expect($usage->key)->toBe('auth.failed')
        ->and($usage->file)->toBe('/app/Foo.php')
        ->and($usage->line)->toBe(12)
        ->and($usage->method)->toBe('__')
        ->and($usage->isLiteral)->toBeTrue();
});

it('can represent a non-literal call site with an empty key', function () {
    $usage = new Usage('', '/app/Foo.php', 3, 'trans', false);

    expect($usage->isLiteral)->toBeFalse()
        ->and($usage->key)->toBe('');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/UsageTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\Usage" not found`.

- [ ] **Step 3: Write the value object**

`src/Support/Usage.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

/**
 * One translation call site found in source.
 *
 * A non-literal call (for example `__($key)`) carries an empty key and
 * `isLiteral = false`. Those are reported as `dynamic` rather than guessed at.
 */
final readonly class Usage
{
    public function __construct(
        public string $key,
        public string $file,
        public int $line,
        public string $method,
        public bool $isLiteral,
    ) {}
}
```

- [ ] **Step 4: Add the config block**

Append to the array in `config/i18n.php`, before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | Source scanning
    |--------------------------------------------------------------------------
    |
    | Where to look for translation calls, and what to leave out of the
    | "unused" report. `ignored_groups` and `ignored_keys` suppress unused
    | keys only; they never hide a key that code uses but no file defines.
    |
    */
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

Note the `null` defaults: `app_path()` and friends cannot be called safely at config-load time in every context, so the resolving happens in `SourceScanner` (Task 2).

- [ ] **Step 5: Add the view dependency**

In `composer.json`, add to `require`, keeping the keys alphabetically sorted:

```json
        "illuminate/view": "^13.0",
```

Then run:

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe C:/laragon/bin/composer/composer.phar update illuminate/view --no-interaction
```

- [ ] **Step 6: Run the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/UsageTest.php
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: 2 passed; Pint `passed`; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: scan config block and Usage value object"
```

---

### Task 2: Tokeniser over plain PHP

This task proves the central claim of the design: a tokeniser separates real calls from calls mentioned inside comments and strings, where a regex cannot.

**Files:**
- Create: `src/Support/SourceScanner.php`
- Create: `tests/Fixtures/scan-app/Plain.php`
- Test: `tests/Unit/SourceScannerTest.php`

**Interfaces:**
- Consumes: `Usage` from Task 1.
- Produces:
  - `SourceScanner::__construct(Repository $config, Filesystem $files)` where `Repository` is `Illuminate\Contracts\Config\Repository`.
  - `SourceScanner::scanFile(string $absolutePath): array` returning `list<Usage>`.
  - `SourceScanner::methods(): array` returning `list<string>`, the recognised function names including the always-on ones.

- [ ] **Step 1: Write the fixture**

`tests/Fixtures/scan-app/Plain.php`:

```php
<?php

// __('comment.example')

$inString = "text that mentions __('in.string')";
$doc = '/** @see __(\'in.docblock\') */';

__('real.literal');
trans('trans.literal');
trans_choice('choice.literal', 2);
\Lang::get('lang.get.literal');
app('translator')->get('translator.literal');

$key = 'dynamic.key';
__($key);
__("interpolated.{$key}");
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/SourceScannerTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

function scanner(array $scan = []): SourceScanner
{
    return new SourceScanner(
        new Repository(['i18n' => ['scan' => array_merge([
            'paths' => null,
            'excluded_paths' => null,
            'methods' => ['__', 'trans', 'trans_choice'],
            'ignored_keys' => [],
            'ignored_groups' => [],
            'cache' => false,
            'cache_path' => null,
        ], $scan)]]),
        new Filesystem,
    );
}

function keysFrom(array $usages): array
{
    return array_values(array_map(fn (Usage $u): string => $u->key, array_filter($usages, fn (Usage $u): bool => $u->isLiteral)));
}

it('never reports a call written inside a comment or a string', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->not->toContain('comment.example')
        ->and($keys)->not->toContain('in.string')
        ->and($keys)->not->toContain('in.docblock');
});

it('finds every supported literal call form', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('real.literal')
        ->and($keys)->toContain('trans.literal')
        ->and($keys)->toContain('choice.literal')
        ->and($keys)->toContain('lang.get.literal')
        ->and($keys)->toContain('translator.literal');
});

it('marks a variable or interpolated argument as non-literal', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $dynamic = array_values(array_filter($usages, fn (Usage $u): bool => ! $u->isLiteral));

    expect($dynamic)->toHaveCount(2);
});

it('records the line number of each finding', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $real = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'real.literal'))[0];

    expect($real->line)->toBe(8)->and($real->method)->toBe('__');
});

it('recognises an application wrapper added through config', function () {
    $keys = keysFrom(scanner(['methods' => ['__', 'trans', 'trans_choice', 'myTrans']])
        ->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('real.literal');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/SourceScannerTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\SourceScanner" not found`.

- [ ] **Step 4: Write the scanner**

`src/Support/SourceScanner.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

/**
 * Finds translation call sites in source.
 *
 * PHP's tokeniser is used rather than a regular expression so that a call
 * written inside a comment, a string literal or a docblock is never mistaken
 * for a real one. A call whose first argument is not a plain string is
 * recorded with `isLiteral = false` and reported as dynamic, never guessed at.
 */
class SourceScanner
{
    /** Always recognised, on top of whatever the application configures. */
    private const ALWAYS = ['__', 'trans', 'trans_choice', 'get', 'choice'];

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        /** @var list<string> $configured */
        $configured = $this->config->get('i18n.scan.methods', []);

        return array_values(array_unique([...self::ALWAYS, ...$configured]));
    }

    /**
     * @return list<Usage>
     */
    public function scanFile(string $absolutePath): array
    {
        $source = (string) $this->files->get($absolutePath);

        return $this->tokenize($source, $absolutePath);
    }

    /**
     * @return list<Usage>
     */
    private function tokenize(string $php, string $file): array
    {
        $tokens = token_get_all($php);
        $methods = $this->methods();
        $usages = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array($token[1], $methods, true)) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $i + 1);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $arg = $this->nextMeaningful($tokens, $open + 1);

            if ($arg === null) {
                continue;
            }

            $usages[] = $this->usageFor($tokens[$arg], $token[1], $file, $token[2]);
        }

        return $usages;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $arg
     */
    private function usageFor(array|string $arg, string $method, string $file, int $line): Usage
    {
        // A plain single- or double-quoted string with no interpolation arrives
        // as one T_CONSTANT_ENCAPSED_STRING. Anything else (a variable, an
        // interpolated string, a concatenation) is not something we can read.
        if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
            $key = substr($arg[1], 1, -1);
            $key = stripcslashes($key);

            return new Usage($key, $file, $line, $method, true);
        }

        return new Usage('', $file, $line, $method, false);
    }
}
```

- [ ] **Step 5: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/SourceScannerTest.php
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: 5 passed; Pint `passed`; PHPStan `[OK] No errors`.

If the line-number assertion fails, correct the expected value in the test to the real line rather than changing the scanner; the fixture's line numbering is the authority.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: tokenizer-based scanner for translation call sites"
```

---

### Task 3: Blade support by compiling first

**Files:**
- Modify: `src/Support/SourceScanner.php`
- Create: `tests/Fixtures/scan-app/page.blade.php`
- Test: `tests/Feature/BladeScanTest.php`

**Interfaces:**
- Consumes: `SourceScanner::scanFile()` from Task 2.
- Produces: `scanFile()` now handles `.blade.php` by compiling to PHP before tokenising. Its signature and return type are unchanged.

This test lives in `tests/Feature` because compiling Blade needs a booted application.

- [ ] **Step 1: Write the fixture**

`tests/Fixtures/scan-app/page.blade.php`:

```blade
{{-- __('blade.comment') --}}
<h1>{{ __('blade.echo') }}</h1>
<p>@lang('blade.directive')</p>
<span>{{ trans('blade.trans') }}</span>
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/BladeScanTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

it('finds calls inside blade echo tags and directives', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $keys = array_values(array_map(
        fn (Usage $u): string => $u->key,
        array_filter($usages, fn (Usage $u): bool => $u->isLiteral),
    ));

    expect($keys)->toContain('blade.echo')
        ->and($keys)->toContain('blade.directive')
        ->and($keys)->toContain('blade.trans');
});

it('ignores a call written inside a blade comment', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $keys = array_map(fn (Usage $u): string => $u->key, $usages);

    expect($keys)->not->toContain('blade.comment');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/BladeScanTest.php`
Expected: FAIL, the assertions find none of the blade keys, because `{{ ... }}` is inline HTML to the tokeniser.

- [ ] **Step 4: Compile Blade before tokenising**

In `src/Support/SourceScanner.php`, add the import:

```php
use Illuminate\Support\Facades\Blade;
```

and replace the body of `scanFile()` with:

```php
    /**
     * @return list<Usage>
     */
    public function scanFile(string $absolutePath): array
    {
        $source = (string) $this->files->get($absolutePath);

        // To PHP's tokeniser a Blade file is almost entirely inline HTML, so
        // `{{ __('x') }}` is never seen. Compiling first turns every directive
        // and echo into real PHP, which also means Laravel's own compiler
        // decides what a directive means instead of us maintaining a list.
        if (str_ends_with($absolutePath, '.blade.php')) {
            $source = Blade::compileString($source);
        }

        return $this->tokenize($source, $absolutePath);
    }
```

- [ ] **Step 5: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all tests pass; Pint `passed`; PHPStan `[OK] No errors`.

- [ ] **Step 6: Pin whether compiled line numbers match the source**

The whole report points developers at a file and a line, so a drifting line
number is a real defect rather than cosmetic. Add this test to
`tests/Feature/BladeScanTest.php` and run it:

```php
it('reports blade findings against their source line', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $echo = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'blade.echo'))[0];

    // page.blade.php line 2 is the <h1> holding {{ __('blade.echo') }}.
    expect($echo->line)->toBe(2);
});
```

Add `use Kurt\Modules\I18n\Support\Usage;` to the test file's imports.

If it passes, Blade's compiler preserved the line and nothing more is needed. If
it fails, do NOT relax the assertion: report the real line it produced, and add
a correction step that maps the compiled line back to the source before building
the `Usage`. Say which happened in your report either way.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: scan blade files by compiling them to php first"
```

---

### Task 4: Directory walking, exclusions and unreadable files

**Files:**
- Modify: `src/Support/SourceScanner.php`
- Create: `tests/Fixtures/scan-app/Broken.php`
- Test: `tests/Feature/ScanWalkTest.php`

**Interfaces:**
- Consumes: `scanFile()` from Tasks 2 and 3.
- Produces:
  - `SourceScanner::scan(): array` returning `array{usages: list<Usage>, warnings: list<array{file: string, reason: string}>}`.
  - `SourceScanner::paths(): array` and `SourceScanner::excludedPaths(): array`, each `list<string>`, resolving the `null` config defaults.

- [ ] **Step 1: Write the broken fixture**

`tests/Fixtures/scan-app/Broken.php`:

```php
<?php

__('never.reached'
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/ScanWalkTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
});

it('walks every php and blade file under the configured paths', function () {
    $result = app(SourceScanner::class)->scan();
    $keys = array_map(fn (Usage $u): string => $u->key, $result['usages']);

    expect($keys)->toContain('real.literal')->and($keys)->toContain('blade.echo');
});

it('records an unparseable file as a warning and keeps going', function () {
    $result = app(SourceScanner::class)->scan();

    expect($result['warnings'])->not->toBeEmpty()
        ->and($result['warnings'][0]['file'])->toContain('Broken.php')
        ->and(array_map(fn (Usage $u): string => $u->key, $result['usages']))->toContain('real.literal');
});

it('skips excluded paths', function () {
    config()->set('i18n.scan.excluded_paths', [__DIR__.'/../Fixtures/scan-app']);

    expect(app(SourceScanner::class)->scan()['usages'])->toBeEmpty();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanWalkTest.php`
Expected: FAIL, `Call to undefined method ...::scan()`.

- [ ] **Step 4: Add walking and warning collection**

Add these imports to `src/Support/SourceScanner.php`:

```php
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
```

and these methods:

```php
    /**
     * @return list<string>
     */
    public function paths(): array
    {
        /** @var list<string>|null $configured */
        $configured = $this->config->get('i18n.scan.paths');

        return $configured ?? [app_path(), resource_path()];
    }

    /**
     * @return list<string>
     */
    public function excludedPaths(): array
    {
        /** @var list<string>|null $configured */
        $configured = $this->config->get('i18n.scan.excluded_paths');

        return $configured ?? [base_path('vendor'), storage_path()];
    }

    /**
     * @return array{usages: list<Usage>, warnings: list<array{file: string, reason: string}>}
     */
    public function scan(): array
    {
        $usages = [];
        $warnings = [];

        foreach ($this->paths() as $root) {
            if (! $this->files->isDirectory($root)) {
                continue;
            }

            foreach ($this->filesUnder($root) as $file) {
                try {
                    $usages = [...$usages, ...$this->scanFile($file)];
                } catch (Throwable $e) {
                    // One malformed file must not cost the whole report; a
                    // report with a named gap beats no report at all.
                    $warnings[] = ['file' => $file, 'reason' => $e->getMessage()];
                }
            }
        }

        return ['usages' => $usages, 'warnings' => $warnings];
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            // SKIP_DOTS keeps . and .. out; symlinks are not followed, so a
            // link cannot walk us outside the configured root or loop forever.
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if ($this->isExcluded($path)) {
                continue;
            }

            $found[] = $path;
        }

        sort($found);

        return $found;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludedPaths() as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }

        return false;
    }
```

Also make `tokenize()` fail loudly on a broken file so the warning path is reachable. Add this at the top of `tokenize()`:

```php
        $tokens = @token_get_all($php, TOKEN_PARSE);
```

`TOKEN_PARSE` makes `token_get_all` throw a `ParseError` on invalid source instead of returning a best-effort token list.

- [ ] **Step 5: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: walk configured paths and survive unparseable files"
```

---

### Task 5: Key resolution against the catalogue

**Files:**
- Create: `src/Support/KeyResolver.php`
- Test: `tests/Unit/KeyResolverTest.php`

**Interfaces:**
- Consumes: the existing `TranslationCatalog`, whose readonly properties are `locales`, `jsonLocales`, `phpGroups` (each `list<string>`) and `vendor` (`list<array{name: string, locales: list<string>, groups: list<string>}>`).
- Produces:
  - `KeyResolver::__construct(TranslationCatalog $catalog, callable $jsonHas)` where `$jsonHas` is `fn (string $key): bool`.
  - `KeyResolver::resolve(string $key): array` returning `array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}`.

The `$jsonHas` callback exists so the resolver stays free of file access; Task 7 supplies a closure backed by `TranslationManager`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/KeyResolverTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\KeyResolver;
use Kurt\Modules\I18n\Support\TranslationCatalog;

function resolver(array $jsonKeys = []): KeyResolver
{
    $catalog = new TranslationCatalog(
        locales: ['en', 'tr'],
        jsonLocales: ['en'],
        phpGroups: ['auth', 'admin/users'],
        vendor: [['name' => 'somepkg', 'locales' => ['en'], 'groups' => ['messages']]],
    );

    return new KeyResolver($catalog, fn (string $key): bool => in_array($key, $jsonKeys, true));
}

it('resolves a key whose first segment is a known group', function () {
    expect(resolver()->resolve('auth.failed'))
        ->toBe(['store' => 'group', 'group' => 'auth', 'item' => 'failed', 'package' => null]);
});

it('resolves a nested group path', function () {
    expect(resolver()->resolve('admin/users.title'))
        ->toBe(['store' => 'group', 'group' => 'admin/users', 'item' => 'title', 'package' => null]);
});

it('resolves a vendor namespaced key', function () {
    expect(resolver()->resolve('somepkg::messages.hello'))
        ->toBe(['store' => 'vendor', 'group' => 'messages', 'item' => 'hello', 'package' => 'somepkg']);
});

it('treats a dotless key as json', function () {
    expect(resolver()->resolve('Welcome back')['store'])->toBe('json');
});

it('falls back to json when a dotted key exists in the json store', function () {
    expect(resolver(['some.dotted.key'])->resolve('some.dotted.key')['store'])->toBe('json');
});

it('reports a dotted key with no matching group and no json entry as ambiguous', function () {
    expect(resolver()->resolve('billing.invoice_sent')['store'])->toBe('ambiguous');
});

it('reports an unknown vendor package as ambiguous', function () {
    expect(resolver()->resolve('nopkg::messages.hello')['store'])->toBe('ambiguous');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/KeyResolverTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\KeyResolver" not found`.

- [ ] **Step 3: Write the resolver**

`src/Support/KeyResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Closure;

/**
 * Places a key found in source against what actually exists on disk.
 *
 * Evidence decides, not a guess: a dotted key is a group key only when that
 * group really exists. A dotted key matching neither a group nor a JSON entry
 * is reported as ambiguous, because writing it to the wrong store would send
 * the developer to the wrong file.
 */
final readonly class KeyResolver
{
    private Closure $jsonHas;

    public function __construct(
        private TranslationCatalog $catalog,
        callable $jsonHas,
    ) {
        $this->jsonHas = $jsonHas(...);
    }

    /**
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    public function resolve(string $key): array
    {
        if (str_contains($key, '::')) {
            return $this->resolveVendor($key);
        }

        // A group key must be "group.item", so a dotless key can only be JSON.
        if (! str_contains($key, '.')) {
            return $this->result('json');
        }

        foreach ($this->groupCandidates($key) as [$group, $item]) {
            if (in_array($group, $this->catalog->phpGroups, true)) {
                return $this->result('group', group: $group, item: $item);
            }
        }

        if (($this->jsonHas)($key)) {
            return $this->result('json');
        }

        return $this->result('ambiguous');
    }

    /**
     * Longest prefix first, so "admin/users.title" prefers the "admin/users"
     * group over an "admin/users.title" group that does not exist.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function groupCandidates(string $key): array
    {
        $candidates = [];
        $offset = strlen($key);

        while (($dot = strrpos($key, '.', $offset - strlen($key) - 1)) !== false) {
            $candidates[] = [substr($key, 0, $dot), substr($key, $dot + 1)];
            $offset = $dot;

            if ($dot === 0) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    private function resolveVendor(string $key): array
    {
        [$package, $rest] = explode('::', $key, 2);

        if (! str_contains($rest, '.')) {
            return $this->result('ambiguous');
        }

        [$group, $item] = explode('.', $rest, 2);

        foreach ($this->catalog->vendor as $vendor) {
            if ($vendor['name'] === $package && in_array($group, $vendor['groups'], true)) {
                return $this->result('vendor', group: $group, item: $item, package: $package);
            }
        }

        return $this->result('ambiguous');
    }

    /**
     * @param  'json'|'group'|'vendor'|'ambiguous'  $store
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    private function result(string $store, ?string $group = null, ?string $item = null, ?string $package = null): array
    {
        return ['store' => $store, 'group' => $group, 'item' => $item, 'package' => $package];
    }
}
```

- [ ] **Step 4: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/KeyResolverTest.php
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: 7 passed; Pint `passed`; PHPStan `[OK] No errors`.

If `groupCandidates()` proves awkward, replace it with a simpler loop that walks every dot position from the right, as long as all 7 tests still pass. The tests define the contract, not the implementation.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: resolve scanned keys against the catalogue"
```

---

### Task 6: Fingerprint cache keyed on files and on config

The config half of the key is the subtle part: changing `scan.methods` leaves every file fingerprint identical, so without it the new method would silently never be scanned.

**Files:**
- Create: `src/Support/ScanCache.php`
- Test: `tests/Feature/ScanCacheTest.php`

**Interfaces:**
- Consumes: `Usage`, and the `i18n.scan` config block.
- Produces:
  - `ScanCache::__construct(Repository $config, Filesystem $files)`.
  - `ScanCache::get(string $file, int $mtime, int $size): ?array` returning `list<Usage>` or null on a miss.
  - `ScanCache::put(string $file, int $mtime, int $size, array $usages): void`.
  - `ScanCache::flush(): void`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ScanCacheTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\Usage;

beforeEach(function () {
    config()->set('i18n.scan.cache', true);
    config()->set('i18n.scan.cache_path', storage_path('framework/cache/i18n-scan-test.json'));
    app(ScanCache::class)->flush();
});

it('returns nothing for a file it has not seen', function () {
    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('returns the stored usages for an unchanged file', function () {
    $usages = [new Usage('a.b', '/a/b.php', 1, '__', true)];
    app(ScanCache::class)->put('/a/b.php', 123, 45, $usages);

    $hit = app(ScanCache::class)->get('/a/b.php', 123, 45);

    expect($hit)->toHaveCount(1)->and($hit[0]->key)->toBe('a.b');
});

it('misses when the file changed', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    expect(app(ScanCache::class)->get('/a/b.php', 999, 45))->toBeNull();
});

it('misses everything when the scan config changed', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // Same file, same fingerprint, but a new method the earlier scan never looked for.
    config()->set('i18n.scan.methods', ['__', 'trans', 'trans_choice', 'myTrans']);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('returns nothing when caching is disabled', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);
    config()->set('i18n.scan.cache', false);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanCacheTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\ScanCache" not found`.

- [ ] **Step 3: Write the cache**

`src/Support/ScanCache.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Fingerprint cache for scan results.
 *
 * Entries are keyed on path, mtime and size, and the whole file is keyed on a
 * hash of the scan configuration. That second half matters: adding a function
 * to `scan.methods` changes no file, so without it the new function would never
 * be scanned and the report would be quietly wrong.
 *
 * The cache is an optimisation, never a source of truth, so an unreadable or
 * corrupt cache file is discarded rather than raised.
 */
class ScanCache
{
    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<Usage>|null
     */
    public function get(string $file, int $mtime, int $size): ?array
    {
        if ($this->config->get('i18n.scan.cache', true) !== true) {
            return null;
        }

        $data = $this->read();
        $entry = $data['files'][$file] ?? null;

        if ($entry === null || $entry['mtime'] !== $mtime || $entry['size'] !== $size) {
            return null;
        }

        return array_map(
            static fn (array $u): Usage => new Usage($u['key'], $u['file'], $u['line'], $u['method'], $u['isLiteral']),
            $entry['usages'],
        );
    }

    /**
     * @param  list<Usage>  $usages
     */
    public function put(string $file, int $mtime, int $size, array $usages): void
    {
        if ($this->config->get('i18n.scan.cache', true) !== true) {
            return;
        }

        $data = $this->read();
        $data['files'][$file] = [
            'mtime' => $mtime,
            'size' => $size,
            'usages' => array_map(static fn (Usage $u): array => [
                'key' => $u->key,
                'file' => $u->file,
                'line' => $u->line,
                'method' => $u->method,
                'isLiteral' => $u->isLiteral,
            ], $usages),
        ];

        $this->write($data);
    }

    public function flush(): void
    {
        $path = $this->path();

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    private function path(): string
    {
        /** @var string|null $configured */
        $configured = $this->config->get('i18n.scan.cache_path');

        return $configured ?? storage_path('framework/cache/i18n-scan.json');
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode($this->config->get('i18n.scan'), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{config: string, files: array<string, array{mtime: int, size: int, usages: list<array{key: string, file: string, line: int, method: string, isLiteral: bool}>}>}
     */
    private function read(): array
    {
        $empty = ['config' => $this->fingerprint(), 'files' => []];
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return $empty;
        }

        try {
            /** @var array{config?: string, files?: array<string, mixed>} $decoded */
            $decoded = json_decode((string) $this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }

        if (($decoded['config'] ?? null) !== $this->fingerprint()) {
            return $empty;
        }

        /** @var array{config: string, files: array<string, array{mtime: int, size: int, usages: list<array{key: string, file: string, line: int, method: string, isLiteral: bool}>}>} $decoded */
        return $decoded;
    }

    /**
     * @param  array{config: string, files: array<string, mixed>}  $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
```

- [ ] **Step 4: Wire the cache into the scanner**

In `src/Support/SourceScanner.php`, add `ScanCache` to the constructor:

```php
    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
        private readonly ScanCache $cache,
    ) {}
```

and in `scan()`, replace the `try` body with:

```php
                try {
                    $mtime = (int) $this->files->lastModified($file);
                    $size = (int) $this->files->size($file);
                    $cached = $this->cache->get($file, $mtime, $size);

                    if ($cached !== null) {
                        $usages = [...$usages, ...$cached];

                        continue;
                    }

                    $found = $this->scanFile($file);
                    $this->cache->put($file, $mtime, $size, $found);
                    $usages = [...$usages, ...$found];
                } catch (Throwable $e) {
                    $warnings[] = ['file' => $file, 'reason' => $e->getMessage()];
                }
```

Update the Task 2 unit test helper `scanner()` to pass a third argument:

```php
        new \Kurt\Modules\I18n\Support\ScanCache(
            new Repository(['i18n' => ['scan' => ['cache' => false, 'cache_path' => null]]]),
            new Filesystem,
        ),
```

- [ ] **Step 5: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: fingerprint cache keyed on files and scan config"
```

---

### Task 7: The four-category report

**Files:**
- Create: `src/Support/ScanReport.php`
- Test: `tests/Feature/ScanReportTest.php`

**Interfaces:**
- Consumes: `SourceScanner::scan()`, `KeyResolver`, `TranslationManager` (`catalog()`, `groups()`, `grid(FileType $type, ?string $group, array $locales)`), `FileType`.
- Produces:
  - `ScanReport::__construct(SourceScanner $scanner, TranslationManager $manager, Repository $config)`.
  - `ScanReport::generate(?array $locales = null): array` returning
    `array{locales: list<string>, missing: array<string, list<string>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}`.

`missing` is keyed by locale. `unused`, `dynamic` and `ambiguous` are locale-independent.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ScanReportTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanReport;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
    config()->set('i18n.scan.ignored_groups', []);
});

it('reports a key used in code but absent from the files as missing', function () {
    $report = app(ScanReport::class)->generate();

    expect($report['missing'])->toBeArray()
        ->and($report['missing']['en'] ?? [])->toContain('real.literal');
});

it('reports non-literal call sites as dynamic without inventing a key', function () {
    $report = app(ScanReport::class)->generate();

    expect($report['dynamic'])->not->toBeEmpty()
        ->and($report['dynamic'][0])->toHaveKeys(['file', 'line', 'method'])
        ->and($report['dynamic'][0])->not->toHaveKey('key');
});

it('reports a dotted literal that fits no store as ambiguous', function () {
    $report = app(ScanReport::class)->generate();
    $keys = array_column($report['ambiguous'], 'key');

    expect($keys)->toContain('real.literal');
});

it('withholds unused and warns when the scan finds nothing', function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/does-not-exist']);

    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->toBe([])
        ->and(array_column($report['warnings'], 'reason'))
        ->toContain('No literal translation calls were found; check i18n.scan.paths.');
});

it('carries scanner warnings through to the report', function () {
    $report = app(ScanReport::class)->generate();

    expect(array_column($report['warnings'], 'file'))
        ->toContain(realpath(__DIR__.'/../Fixtures/scan-app/Broken.php'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanReportTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\ScanReport" not found`.

- [ ] **Step 3: Write the report**

`src/Support/ScanReport.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Kurt\Modules\I18n\Enums\FileType;

/**
 * Combines what code uses with what the files hold.
 *
 * Four categories, deliberately separate. `dynamic` means the key could not be
 * read at all; `ambiguous` means it was read but belongs nowhere we can name.
 * The developer's next action differs, so merging them would leave both
 * unactionable.
 */
class ScanReport
{
    public function __construct(
        private readonly SourceScanner $scanner,
        private readonly TranslationManager $manager,
        private readonly Repository $config,
    ) {}

    /**
     * @param  list<string>|null  $locales
     * @return array{locales: list<string>, missing: array<string, list<string>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
     */
    public function generate(?array $locales = null): array
    {
        $scan = $this->scanner->scan();
        $warnings = $scan['warnings'];
        $catalog = $this->manager->catalog();
        $locales = $locales ?? $catalog->locales;

        $stored = $this->storedKeys($locales);
        $resolver = new KeyResolver($catalog, static fn (string $key): bool => isset($stored['json'][$key]));

        $dynamic = [];
        $ambiguous = [];
        $codeKeys = [];

        foreach ($scan['usages'] as $usage) {
            if (! $usage->isLiteral) {
                $dynamic[] = ['file' => $usage->file, 'line' => $usage->line, 'method' => $usage->method];

                continue;
            }

            if ($resolver->resolve($usage->key)['store'] === 'ambiguous') {
                $ambiguous[] = ['key' => $usage->key, 'file' => $usage->file, 'line' => $usage->line];
            }

            $codeKeys[$usage->key] = true;
        }

        $missing = [];

        foreach ($locales as $locale) {
            $absent = array_values(array_filter(
                array_keys($codeKeys),
                fn (string $key): bool => ! isset($stored['byLocale'][$locale][$key]),
            ));

            if ($absent !== []) {
                $missing[$locale] = $absent;
            }
        }

        if ($codeKeys === []) {
            // Zero literal usages is always misconfiguration, never truth, and
            // publishing "everything is unused" invites someone to delete their
            // whole catalogue.
            $warnings[] = ['file' => '', 'reason' => 'No literal translation calls were found; check i18n.scan.paths.'];

            return $this->result($locales, $missing, [], $dynamic, $ambiguous, $warnings);
        }

        $unused = array_values(array_filter(
            array_keys($stored['all']),
            fn (string $key): bool => ! isset($codeKeys[$key]) && ! $this->isIgnored($key),
        ));

        return $this->result($locales, $missing, $unused, $dynamic, $ambiguous, $warnings);
    }

    /**
     * @param  list<string>  $locales
     * @return array{all: array<string, true>, json: array<string, true>, byLocale: array<string, array<string, true>>}
     */
    private function storedKeys(array $locales): array
    {
        $all = [];
        $json = [];
        $byLocale = array_fill_keys($locales, []);

        // groups() already yields the JSON pseudo-group (group === null) and
        // every vendor group as "package::group", so iterating it is the whole
        // set. Prefixing by the group name reproduces the key form the resolver
        // works with: "auth.failed", "somepkg::messages.hello".
        foreach ($this->manager->groups() as $source) {
            $type = $source['type'];
            $group = $source['group'];
            $grid = $this->manager->grid($type, $group, $locales);
            $prefix = $group !== null ? $group.'.' : '';

            foreach ($grid['keys'] as $key) {
                $full = $prefix.$key;
                $all[$full] = true;

                if ($type === FileType::Json) {
                    $json[$full] = true;
                }

                foreach ($locales as $locale) {
                    if (($grid['rows'][$key][$locale] ?? null) !== null) {
                        $byLocale[$locale][$full] = true;
                    }
                }
            }
        }

        return ['all' => $all, 'json' => $json, 'byLocale' => $byLocale];
    }

    private function isIgnored(string $key): bool
    {
        /** @var list<string> $groups */
        $groups = $this->config->get('i18n.scan.ignored_groups', []);
        /** @var list<string> $keys */
        $keys = $this->config->get('i18n.scan.ignored_keys', []);

        if (in_array($key, $keys, true)) {
            return true;
        }

        if (str_contains($key, '::')) {
            return true;
        }

        foreach ($groups as $group) {
            if (str_starts_with($key, $group.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $locales
     * @param  array<string, list<string>>  $missing
     * @param  list<string>  $unused
     * @param  list<array{file: string, line: int, method: string}>  $dynamic
     * @param  list<array{key: string, file: string, line: int}>  $ambiguous
     * @param  list<array{file: string, reason: string}>  $warnings
     * @return array{locales: list<string>, missing: array<string, list<string>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
     */
    private function result(array $locales, array $missing, array $unused, array $dynamic, array $ambiguous, array $warnings): array
    {
        return [
            'locales' => $locales,
            'missing' => $missing,
            'unused' => $unused,
            'dynamic' => $dynamic,
            'ambiguous' => $ambiguous,
            'warnings' => $warnings,
        ];
    }
}
```

- [ ] **Step 4: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all pass.

`TranslationManager::groups()` returns `list<array{type: FileType, group: string|null}>`, already including the JSON pseudo-group as `group === null` and each vendor group as `"package::group"`. `storedKeys()` above matches that shape exactly, so no adaptation should be needed.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: assemble the four-category scan report"
```

---

### Task 8: API endpoint, README and CI

**Files:**
- Create: `src/Http/Controllers/Api/ScanReportController.php`
- Modify: `routes/api.php`
- Modify: `README.md`
- Test: `tests/Feature/ScanApiTest.php`

**Interfaces:**
- Consumes: `ScanReport::generate(?array $locales)`, and `ApiController::optionalLocalesFromRequest(Request $request): ?array` plus `ApiController::respond(mixed $payload): JsonResponse` from the existing base class.
- Produces: route `GET api/i18n/scan` named `i18n.api.scan`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ScanApiTest.php`:

```php
<?php

declare(strict_types=1);

beforeEach(function () {
    // Routes are registered in packageBooted(), which Testbench runs during
    // setUp(), before this closure. Changing http.mode or auth_middleware here
    // cannot affect middleware already baked into a registered route, so the
    // test authenticates the way every other API test in this suite does.
    $this->actingAs(i18n_actor());

    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
});

it('returns the four categories', function () {
    $this->getJson('api/i18n/scan')
        ->assertOk()
        ->assertJsonStructure(['data' => ['locales', 'missing', 'unused', 'dynamic', 'ambiguous', 'warnings']]);
});

it('honours a locale filter', function () {
    // ApiController::optionalLocalesFromRequest() reads a comma-separated
    // string, which is the dialect every other route in this package speaks.
    $this->getJson('api/i18n/scan?locales=en')
        ->assertOk()
        ->assertJsonPath('data.locales', ['en']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanApiTest.php`
Expected: FAIL with a 404, because the route does not exist.

- [ ] **Step 3: Write the controller**

`src/Http/Controllers/Api/ScanReportController.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\ScanReport;

/**
 * Read-only report of how source code lines up with the translation files.
 *
 * All four categories come from one scan, so they are returned together rather
 * than split across endpoints that would each repeat the work.
 */
final class ScanReportController extends ApiController
{
    public function __invoke(Request $request, ScanReport $report, ScanCache $cache): JsonResponse
    {
        if ($request->boolean('refresh')) {
            $cache->flush();
        }

        return $this->respond($report->generate($this->optionalLocalesFromRequest($request)));
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add the import beside the other controller imports:

```php
use Kurt\Modules\I18n\Http\Controllers\Api\ScanReportController;
```

and add this route inside the existing group, after the `catalog` route:

```php
    Route::get('scan', ScanReportController::class)->name('scan');
```

- [ ] **Step 5: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all pass.

- [ ] **Step 7: Document it in the README**

Add a `## Source scanning` section covering: what the endpoint is (`GET api/i18n/scan`, with comma-separated `locales` and a `refresh` query parameter), what the four categories mean, that `ignored_groups` and `ignored_keys` affect `unused` only and never hide a `missing` key, that a scan finding zero usages withholds `unused` and returns a warning instead, and the full `scan` config block with its defaults. Verify every name you write against the code before committing.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: expose the scan report over the api"
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: `Usage` and config (Task 1), tokeniser and the comment/string proof (Task 2), Blade compilation (Task 3), walking, exclusions, symlinks and unparseable files (Task 4), catalogue-driven resolution with all four branches (Task 5), the config-keyed cache (Task 6), the four categories, the ignore semantics and the zero-usage rail (Task 7), the endpoint, README and error surfacing (Task 8).

Two spec details are handled but worth naming so they are not assumed missing:

- **Symlinks are not followed.** `RecursiveDirectoryIterator` does not follow them unless `FOLLOW_SYMLINKS` is passed, and it is not. The comment in Task 4 records why.
- **`TranslationPathException` for paths escaping a root** is not re-implemented. Task 4 walks only inside `scan.paths` and never resolves a caller-supplied path, so there is no untrusted path to reject in phase A. The exception remains in use where lang files are addressed by request input.

**Placeholder scan.** No TBD or TODO entries; every code step carries complete code.

**Type consistency.** `Usage`'s five constructor parameters are identical in Tasks 1, 2, 6 and 7. `SourceScanner::scan()` returns `array{usages, warnings}` in Task 4 and is consumed with those exact keys in Task 7. `KeyResolver::resolve()` returns the four-key shape in Task 5 and only `['store']` is read in Task 7. `ScanCache`'s three-argument `get`/`put` signature matches between Tasks 6 and the scanner wiring.

**A defect caught during this review.** Task 7's `storedKeys()` originally prepended the JSON pseudo-group by hand and then iterated `TranslationManager::groups()` as well. Reading the real method showed `groups()` already yields the JSON group as `group === null`, so JSON keys would have been collected twice, and vendor groups (returned as `"package::group"`) would have been mangled by the defensive string handling. Both are fixed above and the method is now transcription rather than judgement.
