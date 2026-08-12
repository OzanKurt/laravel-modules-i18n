<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('i18n.scan.paths', [__DIR__.'/../Fixtures/scan-app']);
    config()->set('i18n.scan.excluded_paths', []);
    config()->set('i18n.scan.cache', false);
    config()->set('i18n.enabled_environments', ['testing']);
    $this->actingAs(i18n_actor());
});

it('returns the four categories', function () {
    $this->getJson('api/i18n/scan')
        ->assertOk()
        ->assertJsonStructure(['data' => ['locales', 'missing', 'unused', 'dynamic', 'ambiguous', 'warnings']]);
});

it('honours a locale filter', function () {
    $this->getJson('api/i18n/scan?locales=en')
        ->assertOk()
        ->assertJsonPath('data.locales', ['en']);
});
