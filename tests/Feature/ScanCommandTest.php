<?php

declare(strict_types=1);

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

it('exits 2 and withholds unused when the scan is incomplete', function () {
    // An exact-line expectation, not a substring one: the withheld-unused
    // warning the command prints to explain the exit code says the word
    // "unused" itself, so a substring test could never tell the explanation
    // from the section. A section always announces itself on a line of its own.
    $this->artisan('i18n:scan --only=unused')
        ->doesntExpectOutput('unused')
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

it('rejects an unknown category', function () {
    $this->artisan('i18n:scan --only=missinng')
        ->expectsOutputToContain('missinng')
        ->assertExitCode(64);
});

it('rejects a malformed locale', function () {
    $this->artisan('i18n:scan --locales=../etc')->assertExitCode(64);
});
