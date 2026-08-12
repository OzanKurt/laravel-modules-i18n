<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\Usage;

beforeEach(function () {
    config()->set('i18n.scan.cache', true);
    config()->set('i18n.scan.cache_path', storage_path('framework/cache/i18n-scan-test.json'));
    app(ScanCache::class)->flush();
});

afterEach(function () {
    app(ScanCache::class)->flush();
});

/**
 * Rewrites the cache file on disk through `$mutate`.
 *
 * Corruption is staged on the real file rather than by handing the class a
 * fabricated array, because the failure being guarded against is exactly a
 * file that is already on disk when the code that reads it has moved on.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 */
function scan_cache_mutate(callable $mutate): void
{
    $path = storage_path('framework/cache/i18n-scan-test.json');

    /** @var array<string, mixed> $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    file_put_contents($path, json_encode($mutate($data), JSON_THROW_ON_ERROR));
}

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

it('treats an entry whose usage row has the wrong shape as a miss', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // Matching mtime and size, matching fingerprint, but a line number that is
    // no longer an int. Rebuilding a Usage from it would raise a TypeError; a
    // miss just costs one file a rescan.
    scan_cache_mutate(function (array $data): array {
        $data['files']['/a/b.php']['usages'][0]['line'] = null;

        return $data;
    });

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('ignores a cache file written under a different format version', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // A cache file that survived a package upgrade which changed the stored
    // shape. Its config fingerprint is untouched, so only the format version
    // can tell the reader that these entries are not its own.
    scan_cache_mutate(function (array $data): array {
        $data['version'] = 'written-by-an-older-release';

        return $data;
    });

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('returns nothing when caching is disabled', function () {
    app(ScanCache::class)->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);
    config()->set('i18n.scan.cache', false);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});
