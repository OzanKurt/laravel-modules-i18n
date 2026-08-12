<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
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
        // compileString() never touches the cache, so a throwaway path keeps
        // this unit test free of both the container and the disk.
        new BladeCompiler(new Filesystem, sys_get_temp_dir()),
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

    // The variable, the interpolated string and the concatenation.
    expect($dynamic)->toHaveCount(3);
});

it('records the line number of each finding', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $real = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'real.literal'))[0];

    expect($real->line)->toBe(8)->and($real->method)->toBe('__');
});

it('keeps a single-quoted key with backslashes intact', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('single\quoted\backslashes')
        ->and($keys)->toContain("escaped \\ backslash and ' quote");
});

it('does not mangle a unicode escape in a double-quoted key', function () {
    $keys = keysFrom(scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain("unicode \u{1F600} escape");
});

it('ignores get on a receiver that is not the translator', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $get = array_values(array_filter($usages, fn (Usage $u): bool => $u->method === 'get'));

    expect(array_map(fn (Usage $u): string => $u->key, $get))
        ->toBe(['lang.get.literal', 'translator.literal', 'facade.get.literal']);
});

it('ignores choice on a receiver that is not the translator', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $choice = array_values(array_filter($usages, fn (Usage $u): bool => $u->method === 'choice'));

    expect(array_map(fn (Usage $u): string => $u->key, $choice))
        ->toBe(['lang.choice.literal', 'translator.choice.literal']);
});

it('finds a call written with a qualified name', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');
    $namespaced = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'namespaced.key'));

    expect($namespaced)->toHaveCount(1)
        ->and($namespaced[0]->method)->toBe('trans');
});

it('treats a concatenated argument as dynamic rather than a truncated literal', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php');

    expect(keysFrom($usages))->not->toContain('a')
        ->and(array_values(array_filter($usages, fn (Usage $u): bool => ! $u->isLiteral)))->toHaveCount(3);
});

it('scans a blade file with a scanner built by hand rather than by the container', function () {
    $usages = scanner()->scanFile(__DIR__.'/../Fixtures/scan-app/shifted.blade.php');
    $shifted = array_values(array_filter($usages, fn (Usage $u): bool => $u->key === 'shifted.after.comment'));

    expect($shifted)->toHaveCount(1)->and($shifted[0]->line)->toBe(6);
});

it('recognises an application wrapper added through config', function () {
    $keys = keysFrom(scanner(['methods' => ['__', 'trans', 'trans_choice', 'myTrans']])
        ->scanFile(__DIR__.'/../Fixtures/scan-app/Plain.php'));

    expect($keys)->toContain('real.literal');
});
