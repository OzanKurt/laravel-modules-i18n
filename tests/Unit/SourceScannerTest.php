<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Kurt\Modules\I18n\Support\SourceScanner;
use Kurt\Modules\I18n\Support\Usage;

function scanner(array $scan = []): SourceScanner
{
    return new SourceScanner(
        new Repository(['i18n' => ['scan' => array_merge([
            'paths' => null,
            'excluded_paths' => null,
            'methods' => ['__', 'trans', 'trans_choice'],
            'ignored_keys' => [],
            'ignored_groups' => [],
            'cache' => false,
            'cache_path' => null,
        ], $scan)]]),
        new Filesystem,
    );
}

function keysFrom(array $usages): array
{
    return array_values(array_map(fn (Usage $u): string => $u->key, array_filter($usages, fn (Usage $u): bool => $u->isLiteral)));
}

it('never reports a call written inside a comment or a string', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->not->toContain('comment.example')
        ->and($keys)->not->toContain('in.string')
        ->and($keys)->not->toContain('in.docblock');
});

it('finds every supported literal call form', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('real.literal')
        ->and($keys)->toContain('trans.literal')
        ->and($keys)->toContain('choice.literal')
        ->and($keys)->toContain('lang.get.literal')
        ->and($keys)->toContain('translator.literal');
});

it('marks a variable or interpolated argument as non-literal', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $dynamic = array_values(array_filter($usages, fn (Usage $u): bool => ! $u->isLiteral));

    expect($dynamic)->toHaveCount(2);
});

it('records the line number of each finding', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $real = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'real.literal'))[0];

    expect($real->line)->toBe(8)->and($real->method)->toBe('__');
});

it('recognises an application wrapper added through config', function () {
    $keys = keysFrom(scanner(['methods' => ['__', 'trans', 'trans_choice', 'myTrans']])
        ->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('real.literal');
});
