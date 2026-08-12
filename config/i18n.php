<?php

declare(strict_types=1);
use Kurt\Modules\I18n\Support\NullTranslator;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled environments
    |--------------------------------------------------------------------------
    |
    | The UI writes translation files on disk, so it is restricted by default.
    | In any of these environments access is granted automatically. In every
    | other environment (e.g. "production") a request must additionally pass
    | the "viewI18n" gate — define it in your app to enable the UI there.
    |
    */

    'enabled_environments' => ['local'],

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | The UI and its JSON API are mounted under this prefix with the given
    | middleware. The package always appends its own gate/environment guard.
    |
    */

    'route' => [
        'prefix' => 'i18n',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP (REST API)
    |--------------------------------------------------------------------------
    |
    | The config-convention block consumed by the Core API kit. The JSON REST
    | API under "prefix" is registered only when "mode" is "api" or "ui" — it is
    | "headless" (nothing registered) by default, so a consumer opts in
    | explicitly via I18N_HTTP_MODE. Translation management is an admin surface:
    | every endpoint (reads and writes alike) runs the "auth_middleware" and the
    | "i18n.manageTranslations" gate. "mode" = "ui" additionally serves the
    | bundled translation-manager UI shell under the "route" prefix above.
    |
    */

    'http' => [
        'mode' => env('I18N_HTTP_MODE', 'headless'),
        'prefix' => 'api/i18n',
        'middleware' => ['api'],
        'auth_middleware' => ['auth'],
        'rate_limit' => '60,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | The root translation directory the manager reads from and writes to.
    | When null it resolves to the application's lang_path(). Only files that
    | live inside this root are ever touched (path-traversal is rejected).
    |
    */

    'paths' => [
        'root' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | When enabled, a timestamped copy of a translation file is written before
    | it is overwritten. When the path is null it resolves to
    | storage_path('i18n-backups'). "keep" caps how many backups are retained
    | per source file; older ones are pruned after each write (0 = unlimited).
    |
    */

    'backups' => [
        'enabled' => true,
        'path' => null,
        'keep' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | When null, locales are auto-detected from the translation files on disk.
    | Optionally provide an explicit map of locale code => display label to
    | control ordering and naming, e.g. ['en' => 'English', 'tr' => 'Türkçe'].
    |
    */

    'locales' => null,

    /*
    |--------------------------------------------------------------------------
    | Translator
    |--------------------------------------------------------------------------
    |
    | The class used by the "translate missing keys" action. It must implement
    | Kurt\Modules\I18n\Contracts\Translator. The shipped default refuses to run
    | (it throws) so nothing is silently written untranslated — point this at
    | your own DeepL/Google/LLM-backed implementation to enable the feature.
    |
    */

    'translator' => NullTranslator::class,

    /*
    |--------------------------------------------------------------------------
    | Source scanning
    |--------------------------------------------------------------------------
    |
    | Where to look for translation calls, and what to leave out of the
    | "unused" report. `ignored_groups` and `ignored_keys` suppress unused
    | keys only; they never hide a key that code uses but no file defines.
    |
    */
    'scan' => [
        'paths' => null,            // null resolves to [app_path(), resource_path()]
        'excluded_paths' => null,   // null resolves to [base_path('vendor'), storage_path()]
        'methods' => ['__', 'trans', 'trans_choice'],
        'ignored_keys' => [],
        'ignored_groups' => ['validation', 'passwords', 'auth', 'pagination'],
        'cache' => true,
        'cache_path' => null,       // null resolves to storage/framework/cache/i18n-scan.json
    ],

];
