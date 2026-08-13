# i18n Scan Command (Phase B1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `php artisan i18n:scan`, a read-only command over the Phase A scanner, with narrowing, table and JSON output, and exit codes a CI pipeline can act on.

**Architecture:** A thin `ScanCommand` parses flags, calls the existing `ScanReport::generate()`, hands the result to a pure `ScanOutputFormatter`, and picks an exit code. No scanning logic is added or duplicated; the command never calls the HTTP endpoint, so CI does not need a running web server.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 5, Orchestra Testbench 11, Larastan 3 (level 8), Pint, spatie/laravel-package-tools.

## Global Constraints

- PHP `^8.4`; `illuminate/*` `^13.0`; Testbench `^11.0`; Pest `^5.0`; Larastan `^3.0`; Pint `^1.18`.
- Namespaces: `Kurt\Modules\I18n\Console\Commands\` and `Kurt\Modules\I18n\Support\`; test namespace `Kurt\Modules\I18n\Tests\`.
- `declare(strict_types=1);` on every PHP file.
- PHPStan level 8 with **no** `ignoreErrors` entries and no `@phpstan-ignore` comments.
- **Phase B1 is read-only.** No task may write to a translation file. Writing arrives in B2.
- The command signature is exactly `i18n:scan`.
- Exit codes are exactly: `0` clean, `1` `--fail` with findings, `2` scan incomplete and `unused` requested, `64` usage error.
- When both `1` and `2` apply, `2` wins.
- `--fail` ignores `dynamic` unless `dynamic` is explicitly named in `--only`.
- `enabled_environments` guards HTTP only; the command runs in every environment.
- `--format=json` output must equal `ScanReport::generate()` byte for byte, with no wrapper.
- Working directory `D:\Code\Projects\KurtModules-i18n`, branch `docs/source-scanner-spec`.
- The default `php` on this machine is 8.3 and too old. Every PHP command must run through `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`.
- `tests/Fixtures` is excluded from Pint; existing fixture line-number assertions must keep holding.
- This repo has `core.autocrlf=false` and LF-tracked files. Normalise back to LF if an edit flips a file to CRLF.
- Commit messages must not contain AI attribution or a co-author trailer.
- Never use an em-dash or en-dash in file content or commit messages.

---

## What already exists

`ScanReport::generate(?array $locales = null)` returns:

```php
array{
    locales: list<string>,
    missing: array<string, list<MissingKey>>,   // keyed by locale
    unused: list<string>,
    dynamic: list<array{file: string, line: int, method: string}>,
    ambiguous: list<array{key: string, file: string, line: int}>,
    warnings: list<array{file: string, reason: string}>,
}
```

`MissingKey` is a `@phpstan-type` alias declared on `ScanReport`, not a class:

```php
array{key: string, store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, package: string|null}
```

`ScanReport` already withholds `unused` (returning `[]`) and appends an
explanatory warning when the scan produced any file-level warning, and again
when the scan found zero literal usages. The command reads that state; it does
not re-implement the rule.

`ScanCache::flush()` clears the cache, which is what `--refresh` uses.

`LangPaths::isValidLocale(string $locale): bool` is the existing locale check
the HTTP surface uses.

`I18nServiceProvider` extends `Kurt\Modules\Core\Providers\PackageServiceProvider`
(a spatie `PackageServiceProvider`) and configures itself in
`configurePackage(Package $package)`, currently with `->name()`,
`->hasConfigFile('i18n')` and `->hasTranslations()`. Commands register there via
`->hasCommands([...])`, the plural form, which is what
`laravel-modules-loyalty` and `laravel-modules-blog` both use. There is no
singular `hasCommand` on this version of the package.

## File Structure

| Path | Responsibility |
|---|---|
| `src/Support/ScanOutputFormatter.php` | Pure: a report plus selected categories to table rows or a JSON string |
| `src/Console/Commands/ScanCommand.php` | Parse flags, call `ScanReport`, print, choose the exit code |
| `src/Providers/I18nServiceProvider.php` | One `->hasCommands([...])` line |
| `README.md`, `CHANGELOG.md` | Document the command |

---

### Task 1: The output formatter

The formatter is built first and separately because it is pure: no container, no
filesystem, no Laravel `Command`. That makes the presentation rules testable in
isolation, and Phase C's dashboard will reuse it.

**Files:**
- Create: `src/Support/ScanOutputFormatter.php`
- Test: `tests/Unit/ScanOutputFormatterTest.php`

**Interfaces:**
- Consumes: the `ScanReport::generate()` array shape above.
- Produces:
  - `ScanOutputFormatter::CATEGORIES` = `['missing', 'unused', 'dynamic', 'ambiguous']`
  - `ScanOutputFormatter::json(array $report): string`
  - `ScanOutputFormatter::sections(array $report, array $categories): array` returning
    `list<array{title: string, headers: list<string>, rows: list<list<string>>}>`,
    with empty categories omitted entirely.

- [ ] **Step 1: Write the failing test**

`tests/Unit/ScanOutputFormatterTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanOutputFormatter;

function emptyReport(array $overrides = []): array
{
    return array_merge([
        'locales' => ['en'],
        'missing' => [],
        'unused' => [],
        'dynamic' => [],
        'ambiguous' => [],
        'warnings' => [],
    ], $overrides);
}

it('omits a category that holds nothing', function () {
    $sections = ScanOutputFormatter::sections(emptyReport(), ScanOutputFormatter::CATEGORIES);

    expect($sections)->toBe([]);
});

it('titles a missing section with its locale', function () {
    $report = emptyReport(['missing' => [
        'tr' => [['key' => 'auth.failed', 'store' => 'group', 'group' => 'auth', 'package' => null]],
    ]]);

    $sections = ScanOutputFormatter::sections($report, ['missing']);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['title'])->toBe('missing (tr)')
        ->and($sections[0]['headers'])->toBe(['key', 'store', 'group'])
        ->and($sections[0]['rows'])->toBe([['auth.failed', 'group', 'auth']]);
});

it('renders a null group as a dash', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'Welcome', 'store' => 'json', 'group' => null, 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'])
        ->toBe([['Welcome', 'json', '-']]);
});

it('marks an ambiguous missing key so it is not auto-created', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'billing.sent', 'store' => 'ambiguous', 'group' => null, 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'][0][1])->toBe('ambiguous');
});

it('renders one section per locale for missing', function () {
    $report = emptyReport(['missing' => [
        'tr' => [['key' => 'a.b', 'store' => 'group', 'group' => 'a', 'package' => null]],
        'de' => [['key' => 'a.b', 'store' => 'group', 'group' => 'a', 'package' => null]],
    ]]);

    $titles = array_column(ScanOutputFormatter::sections($report, ['missing']), 'title');

    expect($titles)->toBe(['missing (tr)', 'missing (de)']);
});

it('renders the flat categories', function () {
    $report = emptyReport([
        'unused' => ['passwords.reset'],
        'dynamic' => [['file' => 'app/Foo.php', 'line' => 3, 'method' => '__']],
        'ambiguous' => [['key' => 'x.y', 'file' => 'app/Bar.php', 'line' => 9]],
    ]);

    $sections = ScanOutputFormatter::sections($report, ScanOutputFormatter::CATEGORIES);

    expect(array_column($sections, 'title'))->toBe(['unused', 'dynamic', 'ambiguous'])
        ->and($sections[0]['rows'])->toBe([['passwords.reset']])
        ->and($sections[1]['rows'])->toBe([['app/Foo.php', '3', '__']])
        ->and($sections[2]['rows'])->toBe([['x.y', 'app/Bar.php', '9']]);
});

it('only renders the categories it was asked for', function () {
    $report = emptyReport([
        'unused' => ['a'],
        'dynamic' => [['file' => 'f', 'line' => 1, 'method' => '__']],
    ]);

    expect(array_column(ScanOutputFormatter::sections($report, ['unused']), 'title'))->toBe(['unused']);
});

it('encodes the report as json without a wrapper', function () {
    $report = emptyReport(['unused' => ['a.b']]);

    expect(json_decode(ScanOutputFormatter::json($report), true))->toBe($report);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/ScanOutputFormatterTest.php`
Expected: FAIL, `Class "Kurt\Modules\I18n\Support\ScanOutputFormatter" not found`.

- [ ] **Step 3: Write the formatter**

`src/Support/ScanOutputFormatter.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

/**
 * Turns a scan report into something printable.
 *
 * Pure on purpose: no container, no filesystem, no Laravel Command. The
 * presentation rules are worth testing on their own, and the dashboard will
 * want the same transformation.
 *
 * @phpstan-type MissingKey array{key: string, store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, package: string|null}
 * @phpstan-type Report array{locales: list<string>, missing: array<string, list<MissingKey>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
 * @phpstan-type Section array{title: string, headers: list<string>, rows: list<list<string>>}
 */
final class ScanOutputFormatter
{
    /** @var list<string> */
    public const CATEGORIES = ['missing', 'unused', 'dynamic', 'ambiguous'];

    /**
     * @param  Report  $report
     */
    public static function json(array $report): string
    {
        return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Report  $report
     * @param  list<string>  $categories
     * @return list<Section>
     */
    public static function sections(array $report, array $categories): array
    {
        $sections = [];

        if (in_array('missing', $categories, true)) {
            foreach ($report['missing'] as $locale => $keys) {
                if ($keys === []) {
                    continue;
                }

                $sections[] = [
                    'title' => "missing ({$locale})",
                    'headers' => ['key', 'store', 'group'],
                    'rows' => array_map(
                        static fn (array $k): array => [$k['key'], $k['store'], $k['group'] ?? '-'],
                        $keys,
                    ),
                ];
            }
        }

        if (in_array('unused', $categories, true) && $report['unused'] !== []) {
            $sections[] = [
                'title' => 'unused',
                'headers' => ['key'],
                'rows' => array_map(static fn (string $k): array => [$k], $report['unused']),
            ];
        }

        if (in_array('dynamic', $categories, true) && $report['dynamic'] !== []) {
            $sections[] = [
                'title' => 'dynamic',
                'headers' => ['file', 'line', 'method'],
                'rows' => array_map(
                    static fn (array $d): array => [$d['file'], (string) $d['line'], $d['method']],
                    $report['dynamic'],
                ),
            ];
        }

        if (in_array('ambiguous', $categories, true) && $report['ambiguous'] !== []) {
            $sections[] = [
                'title' => 'ambiguous',
                'headers' => ['key', 'file', 'line'],
                'rows' => array_map(
                    static fn (array $a): array => [$a['key'], $a['file'], (string) $a['line']],
                    $report['ambiguous'],
                ),
            ];
        }

        return $sections;
    }
}
```

- [ ] **Step 4: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Unit/ScanOutputFormatterTest.php
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: 8 passed; Pint `passed`; PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: scan output formatter"
```

---

### Task 2: The command, its flags and its exit codes

**Files:**
- Create: `src/Console/Commands/ScanCommand.php`
- Modify: `src/Providers/I18nServiceProvider.php` (`configurePackage`, adding `->hasCommands([...])`)
- Test: `tests/Feature/ScanCommandTest.php`

**Interfaces:**
- Consumes: `ScanOutputFormatter::CATEGORIES`, `::sections()`, `::json()` from Task 1; `ScanReport::generate(?array $locales = null)`; `ScanCache::flush()`; `LangPaths::isValidLocale(string $locale): bool`.
- Produces: the `i18n:scan` command with its documented flags and exit codes.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ScanCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanReport;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
});

// The fixture tree deliberately contains Broken.php, so every scan against it
// produces a warning. Excluding it is how a test asks for a complete scan.
function withoutBrokenFixture(): void
{
    config()->set('i18n.scan.excluded_paths', [__DIR__.'/../Fixtures/scan-app/Broken.php']);
}

it('exits 2 and withholds unused when the scan is incomplete', function () {
    $this->artisan('i18n:scan --only=unused')
        ->doesntExpectOutputToContain('unused')
        ->assertExitCode(2);
});

it('exits 0 for missing even when the scan is incomplete', function () {
    $this->artisan('i18n:scan --only=missing')->assertExitCode(0);
});

it('prints missing but still exits 2 when unused is also requested', function () {
    $this->artisan('i18n:scan --only=missing,unused')->assertExitCode(2);
});

it('exits 0 with findings when --fail is absent', function () {
    withoutBrokenFixture();

    $this->artisan('i18n:scan --only=missing')->assertExitCode(0);
});

it('exits 1 with findings when --fail is given', function () {
    withoutBrokenFixture();

    $this->artisan('i18n:scan --only=missing --fail')->assertExitCode(1);
});

it('does not fail on dynamic unless dynamic is asked for', function () {
    withoutBrokenFixture();
    // The fixture has dynamic call sites but no ambiguous or unused findings
    // once Broken.php is out, so --fail must ignore them by default.
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app/Dynamic.php']);

    $this->artisan('i18n:scan --fail')->assertExitCode(0);
    $this->artisan('i18n:scan --only=dynamic --fail')->assertExitCode(1);
});

it('rejects an unknown category', function () {
    $this->artisan('i18n:scan --only=missinng')
        ->expectsOutputToContain('missinng')
        ->assertExitCode(64);
});

it('rejects a malformed locale', function () {
    $this->artisan('i18n:scan --locales=../etc')->assertExitCode(64);
});
```

JSON parity is not tested here. It needs the command's output buffer and is the
one assertion that keeps the CLI and the HTTP endpoint from drifting apart, so
it gets its own task rather than a placeholder that asserts nothing.

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanCommandTest.php`
Expected: FAIL, `The command "i18n:scan" does not exist.`

- [ ] **Step 3: Add the dynamic-only fixture**

`tests/Fixtures/scan-app/Dynamic.php`:

```php
<?php

$key = 'some.key';
__($key);
```

- [ ] **Step 4: Write the command**

`src/Console/Commands/ScanCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Console\Commands;

use Illuminate\Console\Command;
use Kurt\Modules\I18n\Support\LangPaths;
use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\ScanOutputFormatter;
use Kurt\Modules\I18n\Support\ScanReport;

/**
 * Read-only report of how source code lines up with the translation files.
 *
 * Deliberately thin: every rule about what the categories mean, and when
 * `unused` may be trusted, lives in ScanReport. This class only turns flags
 * into a call, output into text, and outcomes into an exit code.
 *
 * It calls ScanReport directly rather than the HTTP endpoint, so a CI run needs
 * no web server.
 */
final class ScanCommand extends Command
{
    private const EXIT_CLEAN = 0;
    private const EXIT_FINDINGS = 1;
    private const EXIT_INCOMPLETE = 2;
    private const EXIT_USAGE = 64;

    /** Categories --fail gates on when --only was not given. */
    private const FAIL_BY_DEFAULT = ['missing', 'unused', 'ambiguous'];

    protected $signature = 'i18n:scan
        {--only= : Comma-separated categories: missing, unused, dynamic, ambiguous}
        {--locales= : Comma-separated locales; narrows missing}
        {--format=table : table or json}
        {--fail : Exit non-zero when a selected category holds findings}
        {--refresh : Bypass the scan cache}';

    protected $description = 'Report translation keys used in code but missing from files, and keys stored but unused.';

    public function handle(ScanReport $report, ScanCache $cache): int
    {
        $categories = $this->categories();

        if ($categories === null) {
            return self::EXIT_USAGE;
        }

        $locales = $this->locales();

        if ($locales === false) {
            return self::EXIT_USAGE;
        }

        if ($this->option('refresh')) {
            $cache->flush();
        }

        $result = $report->generate($locales);

        if ($this->option('format') === 'json') {
            $this->line(ScanOutputFormatter::json($result));
        } else {
            $this->renderTables($result, $categories);
        }

        return $this->exitCode($result, $categories);
    }

    /**
     * @return list<string>|null  null signals a usage error, already reported
     */
    private function categories(): ?array
    {
        $only = $this->option('only');

        if (! is_string($only) || trim($only) === '') {
            return ScanOutputFormatter::CATEGORIES;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $only))));
        $unknown = array_diff($requested, ScanOutputFormatter::CATEGORIES);

        if ($unknown !== []) {
            $this->error('Unknown category: '.implode(', ', $unknown).'. Valid: '.implode(', ', ScanOutputFormatter::CATEGORIES).'.');

            return null;
        }

        return $requested;
    }

    /**
     * @return list<string>|null|false  false signals a usage error, already reported
     */
    private function locales(): array|null|false
    {
        $raw = $this->option('locales');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $locales = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($locales as $locale) {
            if (! LangPaths::isValidLocale($locale)) {
                $this->error("Invalid locale [{$locale}].");

                return false;
            }
        }

        return $locales;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $categories
     */
    private function renderTables(array $result, array $categories): void
    {
        /** @var array{locales: list<string>, missing: array<string, list<array{key: string, store: string, group: string|null, package: string|null}>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>} $result */
        foreach (ScanOutputFormatter::sections($result, $categories) as $section) {
            $this->newLine();
            $this->line($section['title']);
            $this->table($section['headers'], $section['rows']);
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->line('warnings');
            $this->table(
                ['file', 'reason'],
                array_map(static fn (array $w): array => [$w['file'], $w['reason']], $result['warnings']),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $categories
     */
    private function exitCode(array $result, array $categories): int
    {
        /** @var array{missing: array<string, list<mixed>>, unused: list<string>, dynamic: list<mixed>, ambiguous: list<mixed>, warnings: list<mixed>} $result */

        // An incomplete scan outranks a finding: the findings themselves came
        // from a walk with known gaps, so blaming them would overstate the run.
        if (in_array('unused', $categories, true) && $result['warnings'] !== []) {
            return self::EXIT_INCOMPLETE;
        }

        if (! $this->option('fail')) {
            return self::EXIT_CLEAN;
        }

        $gated = $this->option('only') === null || $this->option('only') === ''
            ? self::FAIL_BY_DEFAULT
            : $categories;

        foreach ($gated as $category) {
            $found = $category === 'missing'
                ? array_sum(array_map('count', $result['missing']))
                : count($result[$category]);

            if ($found > 0) {
                return self::EXIT_FINDINGS;
            }
        }

        return self::EXIT_CLEAN;
    }
}
```

- [ ] **Step 5: Register the command**

In `src/Providers/I18nServiceProvider.php`, add the import:

```php
use Kurt\Modules\I18n\Console\Commands\ScanCommand;
```

and extend `configurePackage()`:

```php
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-modules-i18n')
            ->hasConfigFile('i18n')
            ->hasTranslations()
            ->hasCommands([
                ScanCommand::class,
            ]);
    }
```

- [ ] **Step 6: Run the tests and the gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: the whole suite passes apart from the one test still marked `todo`; Pint `passed`; PHPStan `[OK] No errors`.

If the dynamic-only test fails because the fixture path produces findings you
did not expect, print the report for that path and adjust the fixture until it
holds dynamic call sites and nothing else. Do not relax the assertion.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: i18n:scan command with narrowing and ci exit codes"
```

---

### Task 3: JSON parity, README and CHANGELOG

The JSON test is separated because it is the one that keeps the CLI and the HTTP
endpoint from drifting apart, and it needs the command's real output buffer.

**Files:**
- Modify: `tests/Feature/ScanCommandTest.php` (complete the `todo` test)
- Modify: `README.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: everything from Tasks 1 and 2.
- Produces: no new code surface.

- [ ] **Step 1: Add the JSON parity test**

Append to `tests/Feature/ScanCommandTest.php`:

```php
it('emits json identical to what the report returns', function () {
    withoutBrokenFixture();

    $expected = app(ScanReport::class)->generate();

    $this->artisan('i18n:scan --format=json');

    $printed = json_decode(trim(Artisan::output()), true);

    expect($printed)->toBe($expected);
});
```

and add the import at the top of the file:

```php
use Illuminate\Support\Facades\Artisan;
```

- [ ] **Step 2: Run it and verify it passes**

Run: `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest tests/Feature/ScanCommandTest.php`
Expected: all tests pass, none skipped.

If `Artisan::output()` comes back empty, the command's output was captured by
Pest's `artisan()` expectation helpers instead. In that case call the command
through `Artisan::call('i18n:scan', ['--format' => 'json'])` and read
`Artisan::output()` after it. Say which form you used in your report.

- [ ] **Step 3: Document the command in the README**

Add a `### The scan command` subsection under the existing source-scanning
section, covering:

- the invocation `php artisan i18n:scan` and each flag: `--only`, `--locales`,
  `--format`, `--fail`, `--refresh`
- the exit codes `0`, `1`, `2` and `64`, and that `2` means the scan was
  incomplete rather than that unused keys were found
- that `--fail` ignores `dynamic` unless `dynamic` is named in `--only`, because
  dynamic keys are a legitimate pattern present in almost every codebase
- that `enabled_environments` does not gate the command, so CI can run it under
  `testing`
- a copy-pasteable CI line: `php artisan i18n:scan --only=missing --fail`

Verify every flag name and exit code against `ScanCommand` before committing.

- [ ] **Step 4: Add a CHANGELOG entry**

Read the existing `[Unreleased]` entries first and match their voice and
heading structure. The entry should say the package gained its first artisan
command, name it, and note that it is read-only.

- [ ] **Step 5: Run the full gates**

```bash
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pest
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/pint
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: all pass, no todo tests remaining.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "docs: document the scan command and its exit codes"
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: the formatter and the
table shape including the `store` column (Task 1), the command, all five flags,
all four exit codes, the `unused` withholding rule, the `--fail` dynamic
exception and the usage errors (Task 2), JSON parity with the endpoint, the
README and the CHANGELOG (Task 3).

Two spec statements are satisfied by existing code rather than by a new task,
recorded so they are not mistaken for gaps:

- **`enabled_environments` does not gate the command.** The gate is applied in
  the provider's HTTP registration, not globally, so a command inherits nothing.
  Task 3 documents it; no code enforces it because none needs to.
- **`unused` is withheld when the scan is incomplete.** `ScanReport` already
  does this and returns `[]` plus a warning. The command's only job is to turn
  that state into exit code `2`, which Task 2 does.

**Placeholder scan.** No TBD or TODO entries, and no placeholder test. An
earlier draft parked a `todo` test at the end of Task 2 and completed it in
Task 3; it asserted nothing in the meantime, so it was removed and JSON parity
is simply introduced in Task 3 where it belongs.

**Type consistency.** `ScanOutputFormatter::sections()` returns
`list<array{title, headers, rows}>` in Task 1 and is consumed with exactly those
keys in Task 2. `CATEGORIES` is the single source of the valid category names
and is used for both validation and the default selection. `ScanReport::generate()`'s
six top-level keys are asserted in Task 3 and read in Task 2's `exitCode()`.

**Two things checked against the real codebase while writing this plan**, rather
than assumed:

- `LangPaths::isValidLocale()` is `public static`, so calling it statically in
  Task 2 is correct.
- Command registration is `->hasCommands([...])`, plural. An earlier draft of
  this plan used a singular `->hasCommand(...)`, which does not exist on this
  version of spatie's `Package`. Both `laravel-modules-loyalty` and
  `laravel-modules-blog` use the plural form; the plan now matches them.

**One judgement call left to the implementer.** Task 3 Step 2 names a fallback
if `Artisan::output()` returns empty under Pest's `artisan()` helper. No test in
this package or its siblings reads command output that way today, so which
capture mechanism wins could not be confirmed without running it. The assertion
itself is fixed; only the mechanism may vary.
