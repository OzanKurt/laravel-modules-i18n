<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\Usage;

it('carries a literal call site', function () {
    $usage = new Usage('auth.failed', '/app/Foo.php', 12, '__', true);

    expect($usage->key)->toBe('auth.failed')
        ->and($usage->file)->toBe('/app/Foo.php')
        ->and($usage->line)->toBe(12)
        ->and($usage->method)->toBe('__')
        ->and($usage->isLiteral)->toBeTrue();
});

it('can represent a non-literal call site with an empty key', function () {
    $usage = new Usage('', '/app/Foo.php', 3, 'trans', false);

    expect($usage->isLiteral)->toBeFalse()
        ->and($usage->key)->toBe('');
});
