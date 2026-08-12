<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanReport;

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
    config()->set('i18n.scan.ignored_groups', []);
});

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

    expect($keys)->toContain('real.literal');
});

it('withholds unused and warns when the scan finds nothing', function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/does-not-exist']);

    $report = app(ScanReport::class)->generate();

    expect($report['unused'])->toBe([])
        ->and(array_column($report['warnings'], 'reason'))
        ->toContain('No literal translation calls were found; check i18n.scan.paths.');
});

it('carries scanner warnings through to the report', function () {
    $report = app(ScanReport::class)->generate();

    expect(array_column($report['warnings'], 'file'))
        ->toContain(realpath(__DIR__.'/../Fixtures/scan-app/Broken.php'));
});
