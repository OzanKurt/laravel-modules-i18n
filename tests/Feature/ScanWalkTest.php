<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
});

/**
 * The literal keys found by a full scan of whatever is configured.
 *
 * @return list<string>
 */
function scan_walk_keys(): array
{
    return array_values(array_map(
        fn (Usage $u): string => $u->key,
        array_filter(app(SourceScanner::class)->scan()['usages'], fn (Usage $u): bool => $u->isLiteral),
    ));
}

/**
 * Points `$link` at the directory `$target`, however this platform can.
 *
 * A real symlink needs a privilege Windows does not hand out by default, so a
 * junction is tried next; neither is guaranteed, hence the boolean.
 */
function scan_walk_link_directory(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $native = fn (string $path): string => str_replace('/', '\\', $path);

        @exec('cmd /c mklink /J "'.$native($link).'" "'.$native($target).'" 2>&1');
    }

    clearstatcache();

    return is_dir($link);
}

/**
 * Takes read permission away from a file, however this platform can.
 *
 * `chmod` is a near no-op on Windows (it only toggles the read-only flag), so
 * an explicit deny entry is used there instead. Returns whether it worked.
 */
function scan_walk_make_unreadable(string $path): bool
{
    @chmod($path, 0000);
    clearstatcache(true, $path);

    if (! is_readable($path) && DIRECTORY_SEPARATOR !== '\\') {
        return true;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $user = (string) getenv('USERNAME');

        if ($user !== '') {
            @exec('icacls "'.str_replace('/', '\\', $path).'" /deny "'.$user.'":(R) 2>&1');
        }
    }

    clearstatcache(true, $path);

    return ! is_readable($path);
}

/**
 * Gives read permission back so the temporary directory can be removed.
 *
 * The deny entry has to go before the chmod, because on Windows `chmod` sets
 * the read-only attribute that would otherwise keep `unlink` from working, and
 * it cannot clear that attribute while the deny entry is still in the way.
 */
function scan_walk_make_readable(string $path): void
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $user = (string) getenv('USERNAME');

        if ($user !== '') {
            @exec('icacls "'.str_replace('/', '\\', $path).'" /remove:d "'.$user.'" 2>&1');
        }
    }

    @chmod($path, 0644);
    clearstatcache(true, $path);
}

/**
 * Removes a directory link without touching whatever it points at.
 */
function scan_walk_unlink_directory(string $link): void
{
    if (is_link($link)) {
        @unlink($link);
    }

    if (is_dir($link)) {
        @rmdir($link);
    }

    clearstatcache();

    if (is_dir($link) && DIRECTORY_SEPARATOR === '\\') {
        @exec('cmd /c rmdir "'.str_replace('/', '\\', $link).'" 2>&1');
    }
}

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

it('never scans a file that resolves to somewhere outside the configured root', function () {
    $base = i18n_tmp_dir();
    mkdir($base.'/root');
    mkdir($base.'/outside');
    file_put_contents($base.'/root/Inside.php', "<?php\n\n__('inside.key');\n");
    file_put_contents($base.'/outside/Outside.php', "<?php\n\n__('outside.key');\n");

    $linked = scan_walk_link_directory($base.'/outside', $base.'/root/linked');

    config()->set('i18n.scan.paths', [$base.'/root']);

    try {
        expect($linked)->toBeTrue()
            ->and(scan_walk_keys())->toContain('inside.key')
            ->and(scan_walk_keys())->not->toContain('outside.key');
    } finally {
        // The link goes first: a recursive delete that meets it while walking
        // leaves it behind, and the whole temporary tree with it.
        scan_walk_unlink_directory($base.'/root/linked');
        i18n_rrmdir($base);
    }
});

it('still scans a root written with a parent segment', function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app/../scan-app']);

    expect(scan_walk_keys())->toContain('real.literal');
});

it('excludes a directory without excluding a sibling whose name starts the same way', function () {
    $dir = i18n_tmp_dir();
    mkdir($dir.'/vendor');
    mkdir($dir.'/vendor-tools');
    file_put_contents($dir.'/vendor/Excluded.php', "<?php\n\n__('vendor.key');\n");
    file_put_contents($dir.'/vendor-tools/Kept.php', "<?php\n\n__('tools.key');\n");

    config()->set('i18n.scan.paths', [$dir]);
    config()->set('i18n.scan.excluded_paths', [$dir.'/vendor']);

    try {
        expect(scan_walk_keys())->toContain('tools.key')
            ->and(scan_walk_keys())->not->toContain('vendor.key');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('excludes a path written with the separator the platform does not use', function () {
    $dir = i18n_tmp_dir();
    $native = str_replace('/', DIRECTORY_SEPARATOR, $dir);
    mkdir($dir.'/vendor');
    file_put_contents($dir.'/vendor/Excluded.php', "<?php\n\n__('vendor.key');\n");
    file_put_contents($dir.'/Kept.php', "<?php\n\n__('kept.key');\n");

    // The root is given the way the platform writes paths, the exclusion the
    // way a developer types one into config; the two must still meet.
    config()->set('i18n.scan.paths', [$native]);
    config()->set('i18n.scan.excluded_paths', [str_replace('\\', '/', $dir).'/vendor']);

    try {
        expect(scan_walk_keys())->toContain('kept.key')
            ->and(scan_walk_keys())->not->toContain('vendor.key');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('reports a root it cannot walk as a warning and scans the rest', function () {
    $dir = i18n_tmp_dir();
    $missing = $dir.'/nowhere';

    config()->set('i18n.scan.paths', [$missing, __DIR__.'/../Fixtures/scan-app']);

    try {
        $result = app(SourceScanner::class)->scan();
        $blamed = array_map(fn (array $warning): string => $warning['file'], $result['warnings']);

        expect(array_map(fn (Usage $u): string => $u->key, $result['usages']))->toContain('real.literal')
            ->and(implode("\n", $blamed))->toContain('nowhere');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('warns about a root that was explicitly configured but does not exist', function () {
    $dir = i18n_tmp_dir();
    $missing = $dir.'/nowhere';

    config()->set('i18n.scan.paths', [$missing]);

    try {
        $result = app(SourceScanner::class)->scan();
        $blamed = array_map(fn (array $warning): string => $warning['file'], $result['warnings']);

        expect(implode("\n", $blamed))->toContain('nowhere');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('does not warn about a missing default root', function () {
    // A missing `resources` directory, or a missing `app` directory, is a
    // legitimate application shape when the developer never configured
    // `i18n.scan.paths` at all; the built-in fallback must not blame it.
    $dir = i18n_tmp_dir();
    $missingAppPath = $dir.'/app-that-is-not-there';

    config()->set('i18n.scan.paths', null);
    config()->set('i18n.scan.excluded_paths', []);
    app()->useAppPath($missingAppPath);

    try {
        $result = app(SourceScanner::class)->scan();
        $blamed = array_map(fn (array $warning): string => $warning['file'], $result['warnings']);

        expect(implode("\n", $blamed))->not->toContain('app-that-is-not-there');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('still reports its usages, and blames nothing, when the cache cannot be written', function () {
    $dir = i18n_tmp_dir();
    file_put_contents($dir.'/Good.php', "<?php\n\n__('good.key');\n");

    // An ordinary file where the cache wants a directory. Creating the cache
    // directory can never succeed under it, on any platform, so the write
    // fails for a reason that has nothing to do with the file being scanned.
    file_put_contents($dir.'/blocked', 'not a directory');

    config()->set('i18n.scan.paths', [$dir]);
    config()->set('i18n.scan.cache', true);
    config()->set('i18n.scan.cache_path', $dir.'/blocked/i18n-scan.json');

    try {
        $result = app(SourceScanner::class)->scan();
        $blamed = implode("\n", array_map(fn (array $warning): string => $warning['file'], $result['warnings']));

        expect(array_map(fn (Usage $u): string => $u->key, $result['usages']))->toContain('good.key')
            ->and($blamed)->not->toContain('Good.php');
    } finally {
        i18n_rrmdir($dir);
    }
});

it('reports a file it cannot read as a warning and scans the rest', function () {
    $dir = i18n_tmp_dir();
    file_put_contents($dir.'/Good.php', "<?php\n\n__('good.key');\n");
    file_put_contents($dir.'/Secret.php', "<?php\n\n__('secret.key');\n");

    if (! scan_walk_make_unreadable($dir.'/Secret.php')) {
        scan_walk_make_readable($dir.'/Secret.php');
        i18n_rrmdir($dir);

        test()->markTestSkipped('This environment cannot take read permission away from a file.');
    }

    config()->set('i18n.scan.paths', [$dir]);

    try {
        $result = app(SourceScanner::class)->scan();
        $blamed = array_map(fn (array $warning): string => $warning['file'], $result['warnings']);

        // The scanner has to notice the failed read itself. Leaning on the
        // framework's error handler to turn the underlying PHP warning into an
        // exception would make the gap invisible wherever that handler is not
        // installed, which is exactly where a silent empty file hurts.
        expect(fn () => app(SourceScanner::class)->scanFile($dir.'/Secret.php'))->toThrow(RuntimeException::class)
            ->and(array_map(fn (Usage $u): string => $u->key, $result['usages']))->toContain('good.key')
            ->and(implode("\n", $blamed))->toContain('Secret.php');
    } finally {
        scan_walk_make_readable($dir.'/Secret.php');
        i18n_rrmdir($dir);
    }
});
