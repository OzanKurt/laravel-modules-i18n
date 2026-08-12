<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

it('finds calls inside blade echo tags and directives', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $keys = array_values(array_map(
        fn (Usage $u): string => $u->key,
        array_filter($usages, fn (Usage $u): bool => $u->isLiteral),
    ));

    expect($keys)->toContain('blade.echo')
        ->and($keys)->toContain('blade.directive')
        ->and($keys)->toContain('blade.trans');
});

it('ignores a call written inside a blade comment', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $keys = array_map(fn (Usage $u): string => $u->key, $usages);

    expect($keys)->not->toContain('blade.comment');
});

it('reports blade findings against their source line', function () {
    $usages = app(SourceScanner::class)->scanFile(__DIR__.'/../Fixtures/scan-app/page.blade.php');
    $echo = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'blade.echo'))[0];

    // page.blade.php line 2 is the <h1> holding {{ __('blade.echo') }}.
    expect($echo->line)->toBe(2);
});
