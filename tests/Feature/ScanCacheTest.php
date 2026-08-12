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
