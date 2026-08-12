<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
    config()->set('i18n.enabled_environments', ['testing']);
    $this->actingAs(i18n_actor());
});

it('returns the four categories', function () {
    $this->getJson('api/i18n/scan')
        ->assertOk()
        ->assertJsonStructure(['data' => ['locales', 'missing', 'unused', 'dynamic', 'ambiguous', 'warnings']]);
});

it('honours a locale filter', function () {
    $this->getJson('api/i18n/scan?locales=en')
        ->assertOk()
        ->assertJsonPath('data.locales', ['en']);
});

it('rejects an invalid locale in the filter', function () {
    $this->getJson('api/i18n/scan?locales=en,b@d')->assertStatus(422);
});

it('reuses a cached entry without refresh but forces a rescan when refresh is requested', function () {
    config()->set('i18n.scan.cache', true);
    $cachePath = sys_get_temp_dir().'/i18n_scan_cache_'.bin2hex(random_bytes(5)).'.json';
    config()->set('i18n.scan.cache_path', $cachePath);

    $file = realpath(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $stat = stat($file);

    // Plant a cache entry for the fixture file at its real mtime/size, but with
    // a usage the file does not actually contain. A cache hit surfaces it in
    // `missing`; a real rescan of the unchanged file never would, because the
    // file has no such call site.
    file_put_contents($cachePath, json_encode([
        'version' => 1,
        'config' => hash('sha256', json_encode(config('i18n.scan'), JSON_THROW_ON_ERROR)),
        'files' => [
            $file => [
                'mtime' => $stat['mtime'],
                'size' => $stat['size'],
                'usages' => [[
                    'key' => 'poisoned.cache.key',
                    'file' => $file,
                    'line' => 1,
                    'method' => '__',
                    'isLiteral' => true,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $cached = $this->getJson('api/i18n/scan?locales=en')->assertOk()->json('data');

    expect($cached['missing']['en'] ?? [])->toContain('poisoned.cache.key')
        ->and($cached['missing']['en'] ?? [])->not->toContain('real.literal');

    $fresh = $this->getJson('api/i18n/scan?locales=en&refresh=1')->assertOk()->json('data');

    expect($fresh['missing']['en'] ?? [])->not->toContain('poisoned.cache.key')
        ->and($fresh['missing']['en'] ?? [])->toContain('real.literal');
});
