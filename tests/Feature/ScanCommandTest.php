<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kurt\Modules\I18n\Support\ScanReport;
use Kurt\Modules\I18n\Support\TranslationManager;

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

/**
 * Point the scan at the tree whose translation keys contain console markup,
 * with a catalogue of its own so the run has exactly one locale and no warning.
 */
function withMarkupFixture(): void
{
    app()->instance(TranslationManager::class, i18n_manager(__DIR__.'/../Fixtures/scan-dynamic-lang'));
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-markup']);
}

it('exits 2 and withholds unused when the scan is incomplete', function () {
    // An exact-line expectation, not a substring one: the withheld-unused
    // warning the command prints to explain the exit code says the word
    // "unused" itself, so a substring test could never tell the explanation
    // from the section. A section always announces itself on a line of its own.
    $this->artisan('i18n:scan --only=unused')
        ->doesntExpectOutput('unused')
        ->assertExitCode(2);
});

it('exits 2 when the walk found no literal calls at all, whatever --only asked for', function () {
    // A scan that read zero call sites cannot vouch for any category, not just
    // `unused`: `missing` is empty because nothing was read, not because
    // nothing is missing. Unlike the withheld-unused rule this one cannot be
    // narrowed away, or a team that moves app/ after publishing the config
    // keeps a permanently green gate that checks nothing.
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/does-not-exist']);

    $this->artisan('i18n:scan --only=missing --fail')->assertExitCode(2);
});

it('exits 0 for missing even when the scan is incomplete', function () {
    $this->artisan('i18n:scan --only=missing')->assertExitCode(0);
});

it('prints missing but still exits 2 when unused is also requested', function () {
    $this->artisan('i18n:scan --only=missing,unused')->assertExitCode(2);
});

it('prefers exit 2 over exit 1 when the scan is incomplete and --fail has findings', function () {
    // Both conditions are live: Broken.php makes the walk incomplete while the
    // fixture tree has missing keys, so --fail alone would exit 1. The contract
    // says the incomplete scan wins, because findings drawn from a walk with
    // known gaps cannot be trusted enough to be blamed. Without --fail this
    // would only distinguish 2 from 0, which is not the rule being pinned.
    $this->artisan('i18n:scan --only=missing,unused --fail')->assertExitCode(2);
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
    // A tree and a catalogue of its own, so the only finding in the run is the
    // dynamic call site: nothing is missing, unused or ambiguous, and no file
    // failed to parse. --fail must therefore ignore it until --only names it.
    // Dynamic call sites like __($key) are legitimate and near-universal, so a
    // --fail that counted them would fail every codebase on its first run.
    app()->instance(TranslationManager::class, i18n_manager(__DIR__.'/../Fixtures/scan-dynamic-lang'));
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-dynamic']);

    $this->artisan('i18n:scan --fail')->assertExitCode(0);
    $this->artisan('i18n:scan --only=dynamic --fail')->assertExitCode(1);
});

it('treats a whitespace-only --only as no --only at all', function () {
    // Same tree as the dynamic test above, where the sole finding is a dynamic
    // call site. "--only=' '" is not a selection, so the --fail gate must be
    // the default one that ignores dynamic, exactly as a bare --fail is.
    app()->instance(TranslationManager::class, i18n_manager(__DIR__.'/../Fixtures/scan-dynamic-lang'));
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-dynamic']);

    $this->artisan('i18n:scan', ['--only' => ' ', '--fail' => true])->assertExitCode(0);
});

it('confirms a clean table run instead of printing nothing at all', function () {
    // An empty buffer and exit 0 read, in a CI log, exactly like a command that
    // never ran. The scan-dynamic tree with its own catalogue has nothing
    // missing and no warning, so this is the genuinely clean case.
    app()->instance(TranslationManager::class, i18n_manager(__DIR__.'/../Fixtures/scan-dynamic-lang'));
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-dynamic']);

    $this->artisan('i18n:scan --only=missing')
        ->expectsOutputToContain('No findings')
        ->assertExitCode(0);
});

it('keeps the clean-run confirmation out of the json payload', function () {
    // json has one job: parse. A friendly line on stdout would break it.
    app()->instance(TranslationManager::class, i18n_manager(__DIR__.'/../Fixtures/scan-dynamic-lang'));
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-dynamic']);

    $expected = app(ScanReport::class)->generate();

    $exit = Artisan::call('i18n:scan', ['--only' => 'missing', '--format' => 'json']);

    expect($exit)->toBe(0)
        ->and(json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
});

it('rejects an unknown category', function () {
    $this->artisan('i18n:scan --only=missinng')
        ->expectsOutputToContain('missinng')
        ->assertExitCode(64);
});

it('rejects an --only that names no category at all', function () {
    // Separators alone survive the empty-string guard but filter down to an
    // empty selection, which would silence every table and both non-zero exit
    // codes. A --fail that cannot fail is worse than no --fail.
    $this->artisan('i18n:scan', ['--only' => ',', '--fail' => true])->assertExitCode(64);
    $this->artisan('i18n:scan', ['--only' => ' , ', '--fail' => true])->assertExitCode(64);
});

it('rejects an unknown format', function () {
    $this->artisan('i18n:scan --format=jsonn')->assertExitCode(64);
});

it('rejects a malformed locale', function () {
    $this->artisan('i18n:scan --locales=../etc')->assertExitCode(64);
});

it('deduplicates a repeated locale', function () {
    // The endpoint's ?locales=en,en answers with a single "en"; the CLI must
    // agree, since the JSON output is asserted to equal the endpoint's report.
    $exit = Artisan::call('i18n:scan', ['--only' => 'missing', '--locales' => 'en,en', '--format' => 'json']);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report['locales'])->toBe(['en']);
});

it('emits json identical to what the report returns', function () {
    withoutBrokenFixture();

    $expected = app(ScanReport::class)->generate();

    Artisan::call('i18n:scan', ['--format' => 'json']);

    $printed = json_decode(trim(Artisan::output()), true);

    expect($printed)->toBe($expected);
});

it('emits a key that contains console markup unchanged in json', function () {
    // Everything the command writes through line() is parsed by Symfony's
    // output formatter, and a translation key is free to contain angle
    // brackets. If the formatter gets to the payload it silently rewrites the
    // key, and the printed JSON stops matching the report every other consumer
    // reads: the one invariant the JSON format exists to hold.
    withMarkupFixture();

    $expected = app(ScanReport::class)->generate();

    Artisan::call('i18n:scan', ['--format' => 'json']);

    $printed = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($printed)->toBe($expected)
        ->and(array_column($printed['missing']['en'], 'key'))
        ->toContain('Press <info>enter</info> to continue');
});

it('prints a key that contains console markup literally in a table', function () {
    withMarkupFixture();

    // Not merely "does not crash": a rewritten key in the table is a key
    // nobody can grep for in their own source, which is what the table is for.
    $this->artisan('i18n:scan')
        ->expectsOutputToContain('Press <info>enter</info> to continue')
        ->assertExitCode(0);
});

it('survives a key whose markup names a colour the formatter does not know', function () {
    // "<fg=chartreuse>" is not a style Symfony can build, and an unbuildable
    // style is an uncaught InvalidArgumentException, not a stripped tag. No
    // flag is involved: a CI job gets a stack trace instead of a report.
    withMarkupFixture();

    $this->artisan('i18n:scan')->assertExitCode(0);
    $this->artisan('i18n:scan --format=json')->assertExitCode(0);
});
