<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\ScanOutputFormatter;

function emptyReport(array $overrides = []): array
{
    return array_merge([
        'locales' => ['en'],
        'missing' => [],
        'unused' => [],
        'dynamic' => [],
        'ambiguous' => [],
        'warnings' => [],
    ], $overrides);
}

it('omits a category that holds nothing', function () {
    $sections = ScanOutputFormatter::sections(emptyReport(), ScanOutputFormatter::CATEGORIES);

    expect($sections)->toBe([]);
});

it('titles a missing section with its locale', function () {
    $report = emptyReport(['missing' => [
        'tr' => [['key' => 'auth.failed', 'store' => 'group', 'group' => 'auth', 'package' => null]],
    ]]);

    $sections = ScanOutputFormatter::sections($report, ['missing']);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['title'])->toBe('missing (tr)')
        ->and($sections[0]['headers'])->toBe(['key', 'store', 'group'])
        ->and($sections[0]['rows'])->toBe([['auth.failed', 'group', 'auth']]);
});

it('renders a null group as a dash', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'Welcome', 'store' => 'json', 'group' => null, 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'])
        ->toBe([['Welcome', 'json', '-']]);
});

it('renders a vendor missing key group cell as package::group', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'somepkg::messages.hello', 'store' => 'vendor', 'group' => 'messages', 'package' => 'somepkg']],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'])
        ->toBe([['somepkg::messages.hello', 'vendor', 'somepkg::messages']]);
});

it('renders a project group key group cell as the plain group name', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'auth.failed', 'store' => 'group', 'group' => 'auth', 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'])
        ->toBe([['auth.failed', 'group', 'auth']]);
});

it('renders a json-store key with a null group as a dash', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'Welcome', 'store' => 'json', 'group' => null, 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'])
        ->toBe([['Welcome', 'json', '-']]);
});

it('marks an ambiguous missing key so it is not auto-created', function () {
    $report = emptyReport(['missing' => [
        'en' => [['key' => 'billing.sent', 'store' => 'ambiguous', 'group' => null, 'package' => null]],
    ]]);

    expect(ScanOutputFormatter::sections($report, ['missing'])[0]['rows'][0][1])->toBe('ambiguous');
});

it('renders one section per locale for missing', function () {
    $report = emptyReport(['missing' => [
        'tr' => [['key' => 'a.b', 'store' => 'group', 'group' => 'a', 'package' => null]],
        'de' => [['key' => 'a.b', 'store' => 'group', 'group' => 'a', 'package' => null]],
    ]]);

    $titles = array_column(ScanOutputFormatter::sections($report, ['missing']), 'title');

    expect($titles)->toBe(['missing (tr)', 'missing (de)']);
});

it('renders the flat categories', function () {
    $report = emptyReport([
        'unused' => ['passwords.reset'],
        'dynamic' => [['file' => 'app/Foo.php', 'line' => 3, 'method' => '__']],
        'ambiguous' => [['key' => 'x.y', 'file' => 'app/Bar.php', 'line' => 9]],
    ]);

    $sections = ScanOutputFormatter::sections($report, ScanOutputFormatter::CATEGORIES);

    expect(array_column($sections, 'title'))->toBe(['unused', 'dynamic', 'ambiguous'])
        ->and($sections[0]['rows'])->toBe([['passwords.reset']])
        ->and($sections[1]['rows'])->toBe([['app/Foo.php', '3', '__']])
        ->and($sections[2]['rows'])->toBe([['x.y', 'app/Bar.php', '9']]);
});

it('only renders the categories it was asked for', function () {
    $report = emptyReport([
        'unused' => ['a'],
        'dynamic' => [['file' => 'f', 'line' => 1, 'method' => '__']],
    ]);

    expect(array_column(ScanOutputFormatter::sections($report, ['unused']), 'title'))->toBe(['unused']);
});

it('encodes the report as json without a wrapper', function () {
    $report = emptyReport(['unused' => ['a.b']]);

    expect(json_decode(ScanOutputFormatter::json($report), true))->toBe($report);
});
