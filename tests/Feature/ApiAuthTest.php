<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Support\TranslationManager;

beforeEach(function (): void {
    $this->root = i18n_tmp_dir();
    $this->app->instance(TranslationManager::class, i18n_manager($this->root));
});

afterEach(function (): void {
    i18n_rrmdir($this->root);
});

it('rejects unauthenticated reads with 401', function (): void {
    $this->getJson('/api/i18n/catalog')->assertUnauthorized();
    $this->getJson('/api/i18n/groups')->assertUnauthorized();
    $this->getJson('/api/i18n/json?locales=en')->assertUnauthorized();
    $this->getJson('/api/i18n/scan')->assertUnauthorized();
});

it('rejects unauthenticated writes with 401', function (): void {
    $this->patchJson('/api/i18n/json', ['baseHashes' => [], 'ops' => []])->assertUnauthorized();
    $this->postJson('/api/i18n/import', [])->assertUnauthorized();
    $this->putJson('/api/i18n/translations', [])->assertUnauthorized();
    $this->deleteJson('/api/i18n/translations', [])->assertUnauthorized();
    $this->postJson('/api/i18n/translate-missing', [])->assertUnauthorized();
    $this->postJson('/api/i18n/locales', [])->assertUnauthorized();
});

it('forbids an authenticated actor the gate denies with 403', function (): void {
    // Authenticated, but the manageTranslations gate denies outside the
    // module's enabled environments and with no consumer override.
    config()->set('i18n.enabled_environments', ['production']);
    $this->actingAs(i18n_actor());

    $this->getJson('/api/i18n/catalog')->assertForbidden();
    $this->patchJson('/api/i18n/json', ['baseHashes' => [], 'ops' => []])->assertForbidden();
});

it('allows an authenticated actor the gate permits', function (): void {
    config()->set('i18n.enabled_environments', ['testing']);
    $this->actingAs(i18n_actor());

    $this->getJson('/api/i18n/catalog')->assertOk();
});
