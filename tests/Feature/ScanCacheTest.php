<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
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
 * Stores one entry and writes it out, the way one scan would.
 *
 * `put()` only records in memory now, so a test about what a *later* reader
 * sees has to persist first. The container hands back a fresh instance every
 * time, so the reader in each test is genuinely a second one.
 *
 * @param  list<Usage>  $usages
 */
function scan_cache_store(string $file, int $mtime, int $size, array $usages): void
{
    $cache = app(ScanCache::class);
    $cache->put($file, $mtime, $size, $usages);
    $cache->persist();
}

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
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    $hit = app(ScanCache::class)->get('/a/b.php', 123, 45);

    expect($hit)->toHaveCount(1)->and($hit[0]->key)->toBe('a.b');
});

it('returns an entry it was given before anything was written back', function () {
    $cache = app(ScanCache::class);
    $cache->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // Two scans of the same file inside one walk must not both re-tokenise it
    // just because the document has not reached disk yet.
    $hit = $cache->get('/a/b.php', 123, 45);

    expect($hit)->toHaveCount(1)->and($hit[0]->key)->toBe('a.b');
});

it('writes nothing until it is asked to persist, and everything when it is', function () {
    $path = storage_path('framework/cache/i18n-scan-test.json');
    $cache = app(ScanCache::class);

    foreach (range(1, 3) as $i) {
        $cache->put("/a/b{$i}.php", 123, 45, [new Usage("a.b{$i}", "/a/b{$i}.php", 1, '__', true)]);
    }

    // One write per file would re-encode every entry stored so far for every
    // entry added, which past a few hundred files costs far more than the
    // tokenising the cache exists to save.
    expect(file_exists($path))->toBeFalse();

    $cache->persist();

    /** @var array<string, mixed> $written */
    $written = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($written['files']))->toBe(['/a/b1.php', '/a/b2.php', '/a/b3.php']);
});

it('forgets what it holds when it is flushed', function () {
    $path = storage_path('framework/cache/i18n-scan-test.json');
    $cache = app(ScanCache::class);
    $cache->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);
    $cache->flush();
    $cache->persist();

    // A refresh has to mean a refresh: a persist that ran afterwards must not
    // put back the document the flush just threw away.
    expect(file_exists($path))->toBeFalse()
        ->and($cache->get('/a/b.php', 123, 45))->toBeNull();
});

it('misses when the file changed', function () {
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    expect(app(ScanCache::class)->get('/a/b.php', 999, 45))->toBeNull();
});

it('misses everything when the scan config changed', function () {
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // Same file, same fingerprint, but a new method the earlier scan never looked for.
    config()->set('i18n.scan.methods', ['__', 'trans', 'trans_choice', 'myTrans']);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('treats a cache file it cannot read as a miss rather than an error', function () {
    $files = new class extends Filesystem
    {
        public function exists($path)
        {
            return true;
        }

        public function get($path, $lock = false)
        {
            // What a file written by the deploy user and read by php-fpm looks
            // like from here: PHP raises a warning, and Laravel's error handler
            // turns that into this.
            throw new ErrorException("file_get_contents({$path}): Failed to open stream: Permission denied");
        }
    };

    // A read that raised instead of missing would take every file in the walk
    // with it: zero usages, one warning per file, and `unused` withheld.
    expect((new ScanCache(config(), $files))->get('/a/b.php', 123, 45))->toBeNull();
});

it('treats configuration it cannot fingerprint as a miss rather than an error', function () {
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

    // NAN cannot be JSON encoded, so the hash the whole document is keyed on
    // cannot be taken at all. That is not a reason to fail a scan.
    config()->set('i18n.scan.methods', [NAN]);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('treats an entry whose usage row has the wrong shape as a miss', function () {
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

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
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);

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
    scan_cache_store('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);
    config()->set('i18n.scan.cache', false);

    expect(app(ScanCache::class)->get('/a/b.php', 123, 45))->toBeNull();
});

it('writes nothing at all when caching is disabled', function () {
    $path = storage_path('framework/cache/i18n-scan-test.json');
    config()->set('i18n.scan.cache', false);

    $cache = app(ScanCache::class);
    $cache->put('/a/b.php', 123, 45, [new Usage('a.b', '/a/b.php', 1, '__', true)]);
    $cache->persist();

    expect(file_exists($path))->toBeFalse();
});
