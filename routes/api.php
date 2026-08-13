<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kurt\Modules\I18n\Http\Controllers\Api\CatalogController;
use Kurt\Modules\I18n\Http\Controllers\Api\ExportController;
use Kurt\Modules\I18n\Http\Controllers\Api\GroupController;
use Kurt\Modules\I18n\Http\Controllers\Api\ImportController;
use Kurt\Modules\I18n\Http\Controllers\Api\JsonTranslationController;
use Kurt\Modules\I18n\Http\Controllers\Api\LocaleController;
use Kurt\Modules\I18n\Http\Controllers\Api\MissingKeyReportController;
use Kurt\Modules\I18n\Http\Controllers\Api\PhpGroupController;
use Kurt\Modules\I18n\Http\Controllers\Api\ScanReportController;
use Kurt\Modules\I18n\Http\Controllers\Api\TranslateMissingController;
use Kurt\Modules\I18n\Http\Controllers\Api\TranslationController;

/*
|--------------------------------------------------------------------------
| i18n REST API
|--------------------------------------------------------------------------
|
| The outer group (prefix "api/i18n", base middleware, throttle, "i18n.api."
| name prefix) is applied by PackageServiceProvider::registerModuleApi().
|
| Translation management is an admin surface, so unlike the Core kit's default
| public-read / authenticated-write split, EVERY endpoint here — reads and
| writes alike — runs behind the module auth middleware and the
| "i18n.manageTranslations" gate. Nothing is registered at all until a consumer
| opts in via I18N_HTTP_MODE=api (or ui).
|
*/

Route::middleware([
    ...(array) config('i18n.http.auth_middleware', ['auth']),
    'can:i18n.manageTranslations',
])->group(function (): void {
    // Discovery.
    Route::get('catalog', CatalogController::class)->name('catalog');
    Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
    Route::get('locales', [LocaleController::class, 'index'])->name('locales.index');

    // Group grids (a group's keys/values across locales).
    Route::get('json', [JsonTranslationController::class, 'show'])->name('json.show');
    Route::get('php/{group}', [PhpGroupController::class, 'show'])->where('group', '.*')->name('php.show');

    // Single-key reads/writes over the safe write path.
    Route::get('translations', [TranslationController::class, 'show'])->name('translations.show');
    Route::put('translations', [TranslationController::class, 'set'])->name('translations.set');
    Route::delete('translations', [TranslationController::class, 'destroy'])->name('translations.destroy');

    // Batch grid writes.
    Route::patch('json', [JsonTranslationController::class, 'update'])->name('json.update');
    Route::patch('php/{group}', [PhpGroupController::class, 'update'])->where('group', '.*')->name('php.update');

    // Locale creation.
    Route::post('locales', [LocaleController::class, 'store'])->name('locales.store');

    // Reporting. Both of these scan every group in one pass and compute an
    // answer, which is what sets them apart from the discovery endpoints
    // above: those only list what already exists.
    Route::get('report/missing', MissingKeyReportController::class)->name('report.missing');
    Route::get('report/scan', ScanReportController::class)->name('report.scan');

    // Portable import / export + machine translation.
    Route::get('export', ExportController::class)->name('export');
    Route::post('import', ImportController::class)->name('import');
    Route::post('translate-missing', TranslateMissingController::class)->name('translate-missing');
});
