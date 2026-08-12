<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanReport;
use Kurt\Modules\I18n\Support\TranslationManager;
use Kurt\Modules\I18n\Tests\TestCase;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);

    // Broken.php is deliberately unparseable, and a scan that records any
    // file-level warning withholds `unused` entirely. Every test about what
    // `unused` contains therefore leaves it out of the walk; the tests about
    // warnings put it back by clearing this list.
    config()->set('i18n.scan.excluded_paths', [__DIR__.'/../Fixtures/scan-app/Broken.php']);
    config()->set('i18n.scan.cache', false);
    config()->set('i18n.scan.ignored_groups', []);
    config()->set('i18n.scan.ignored_keys', []);

    // Only the tests that need a catalogue of their own create one; the rest
    // read the application's own lang directory, as the default path does.
    $this->root = null;
});

afterEach(function () {
    if ($this->root !== null) {
        i18n_rrmdir($this->root);
    }
});

/**
 * Point the report at a throwaway translation root and return its path.
 *
 * The catalogue has to be built from real files, because the behaviour under
 * test is exactly which locales on disk feed which category.
 */
function scan_report_root(TestCase $case): string
{
    $case->root = i18n_tmp_dir();
    app()->instance(TranslationManager::class, i18n_manager($case->root));

    return $case->root;
}

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

    // An ambiguous key is by definition not found in any store, so it is also
    // reported missing for every requested locale: both are true of it at
    // once, and together they mean the key is unplaceable, not merely
    // untranslated.
    expect($keys)->toContain('real.literal')
        ->and($report['missing']['en'] ?? [])->toContain('real.literal');
});

it('withholds unused and warns when the scan finds nothing', function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/does-not-exist']);

    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->toBe([])
        ->and(array_column($report['warnings'], 'reason'))
        ->toContain('No literal translation calls were found; check i18n.scan.paths.');
});

it('carries scanner warnings through to the report', function () {
    config()->set('i18n.scan.excluded_paths', []);

    $report = app(ScanReport::class)->generate();

    // The fixtures live outside the application root, so this also pins the
    // fallback: a path that cannot be written against `base_path()` is
    // reported absolute rather than made to climb out of the root.
    expect(array_column($report['warnings'], 'file'))
        ->toContain(realpath(__DIR__.'/../Fixtures/scan-app/Broken.php'));
});

it('reports paths relative to the application root', function () {
    $dir = base_path('i18n-scan-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/Dynamic.php', "<?php\n\n\$key = 'a.key';\n__(\$key);\n");
    file_put_contents($dir.'/Broken.php', "<?php\n\nclass {\n");

    config()->set('i18n.scan.paths', [$dir]);
    config()->set('i18n.scan.excluded_paths', []);

    $relative = str_replace('\\', '/', substr($dir, strlen(base_path()) + 1));

    try {
        // A UI wants "app/Http/Controllers/HomeController.php", and an endpoint
        // any reader can call should not be publishing the server's deployment
        // layout either.
        $report = app(ScanReport::class)->generate();

        expect(array_column($report['dynamic'], 'file'))->toBe([$relative.'/Dynamic.php'])
            ->and(array_column($report['warnings'], 'file'))->toContain($relative.'/Broken.php');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('withholds unused and warns when any file failed to scan', function () {
    config()->set('i18n.scan.excluded_paths', []);

    $root = scan_report_root($this);
    mkdir($root.'/en', 0777, true);
    file_put_contents($root.'/en/dashboard.php', "<?php return ['stale' => 'Stale'];");

    // Broken.php is back in the walk, so the scan is partial. A key used only
    // in a file that failed is indistinguishable from a key nothing uses, and
    // a consumer acting on `unused` would delete one the application calls.
    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->toBe([])
        ->and(array_column($report['warnings'], 'reason'))
        ->toContain('Some files could not be scanned, so unused was withheld; a key used only in a file that failed would look unused.');
});

it('still reports missing and the other categories when a file failed to scan', function () {
    config()->set('i18n.scan.excluded_paths', []);

    // Only `unused` is advice to delete something, so only `unused` is
    // withheld; a partial walk still saw plenty worth reporting.
    $report = app(ScanReport::class)->generate();

    expect($report['missing']['en'] ?? [])->toContain('real.literal')
        ->and($report['dynamic'])->not->toBeEmpty();
});

it('reports a stored key that no code uses as unused', function () {
    $root = scan_report_root($this);
    mkdir($root.'/en', 0777, true);
    file_put_contents($root.'/en/dashboard.php', "<?php return ['stale' => 'Stale'];");

    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->toContain('dashboard.stale');
});

it('keeps a key defined only in a locale that was not requested in unused', function () {
    $root = scan_report_root($this);
    file_put_contents($root.'/en.json', (string) json_encode(['english.only.stale' => 'Stale']));
    file_put_contents($root.'/tr.json', (string) json_encode(['turkish.only.stale' => 'Bayat']));

    // `unused` is locale-independent by design: asking about "tr" narrows which
    // locales are checked for missing keys, never which files count as defined.
    $report = app(ScanReport::class)->generate(['tr']);

    expect($report['unused'])->toContain('english.only.stale')
        ->and($report['unused'])->toContain('turkish.only.stale');
});

it('resolves a dotted json key defined only in a locale that was not requested as json', function () {
    $root = scan_report_root($this);
    file_put_contents($root.'/en.json', (string) json_encode(['real.literal' => 'A real one']));
    file_put_contents($root.'/tr.json', (string) json_encode(['unrelated' => 'Alakasiz']));

    // The group side already reads the locale-independent catalogue, so the
    // JSON side must not disagree with it just because "en" was left out.
    $report = app(ScanReport::class)->generate(['tr']);

    expect(array_column($report['ambiguous'], 'key'))->not->toContain('real.literal');
});

it('falls back to the whole catalogue when an empty locale list is requested', function () {
    $root = scan_report_root($this);
    file_put_contents($root.'/en.json', (string) json_encode(['english.only.stale' => 'Stale']));
    file_put_contents($root.'/tr.json', (string) json_encode([]));

    $report = app(ScanReport::class)->generate([]);

    expect($report['locales'])->toBe(['en', 'tr'])
        ->and($report['missing'])->toHaveKeys(['en', 'tr'])
        ->and($report['unused'])->toContain('english.only.stale');
});

it('still reports a used but undefined key from an ignored group as missing', function () {
    $root = scan_report_root($this);
    mkdir($root.'/en', 0777, true);
    file_put_contents($root.'/en/real.php', "<?php return ['stale' => 'Stale'];");
    config()->set('i18n.scan.ignored_groups', ['real']);

    $report = app(ScanReport::class)->generate();

    // Ignoring a group withholds its unused keys. A key the code calls and no
    // file defines is a real gap either way, so it stays in `missing`.
    expect($report['missing']['en'] ?? [])->toContain('real.literal')
        ->and($report['unused'])->not->toContain('real.stale')
        ->and($report['unused'])->not->toContain('real.literal');
});

it('still reports a used but undefined key named by ignored_keys as missing', function () {
    $root = scan_report_root($this);
    mkdir($root.'/en', 0777, true);
    file_put_contents($root.'/en/real.php', "<?php return ['stale' => 'Stale'];");
    config()->set('i18n.scan.ignored_keys', ['real.literal', 'real.stale']);

    $report = app(ScanReport::class)->generate();

    expect($report['missing']['en'] ?? [])->toContain('real.literal')
        ->and($report['unused'])->not->toContain('real.stale')
        ->and($report['unused'])->not->toContain('real.literal');
});

it('never reports a vendor namespaced key as unused, even with both ignore lists empty', function () {
    $root = scan_report_root($this);
    mkdir($root.'/vendor/firewall/en', 0777, true);
    file_put_contents($root.'/vendor/firewall/en/notifications.php', "<?php return ['stale' => 'Stale'];");

    // ignored_groups and ignored_keys are both empty (see beforeEach), yet a
    // vendor-namespaced key is still withheld from unused: it belongs to the
    // package that ships it, so this application is not the one to judge it
    // unused.
    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->not->toContain('firewall::notifications.stale');
});
