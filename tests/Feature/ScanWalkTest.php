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
