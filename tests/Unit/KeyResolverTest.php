<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\KeyResolver;
use Kurt\Modules\I18n\Support\TranslationCatalog;

function resolver(array $jsonKeys = []): KeyResolver
{
    $catalog = new TranslationCatalog(
        locales: ['en', 'tr'],
        jsonLocales: ['en'],
        phpGroups: ['auth', 'admin/users'],
        vendor: [['name' => 'somepkg', 'locales' => ['en'], 'groups' => ['messages']]],
    );

    return new KeyResolver($catalog, fn (string $key): bool => in_array($key, $jsonKeys, true));
}

it('resolves a key whose first segment is a known group', function () {
    expect(resolver()->resolve('auth.failed'))
        ->toBe(['store' => 'group', 'group' => 'auth', 'item' => 'failed', 'package' => null]);
});

it('resolves a nested group path', function () {
    expect(resolver()->resolve('admin/users.title'))
        ->toBe(['store' => 'group', 'group' => 'admin/users', 'item' => 'title', 'package' => null]);
});

it('resolves a vendor namespaced key', function () {
    expect(resolver()->resolve('somepkg::messages.hello'))
        ->toBe(['store' => 'vendor', 'group' => 'messages', 'item' => 'hello', 'package' => 'somepkg']);
});

it('treats a dotless key as json', function () {
    expect(resolver()->resolve('Welcome back')['store'])->toBe('json');
});

it('falls back to json when a dotted key exists in the json store', function () {
    expect(resolver(['some.dotted.key'])->resolve('some.dotted.key')['store'])->toBe('json');
});

it('reports a dotted key with no matching group and no json entry as ambiguous', function () {
    expect(resolver()->resolve('billing.invoice_sent')['store'])->toBe('ambiguous');
});

it('reports an unknown vendor package as ambiguous', function () {
    expect(resolver()->resolve('nopkg::messages.hello')['store'])->toBe('ambiguous');
});
