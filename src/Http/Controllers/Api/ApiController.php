<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Http\Controllers\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Kurt\Modules\Core\Http\Controllers\ApiController as CoreApiController;
use Kurt\Modules\I18n\Exceptions\TranslationConflictException;
use Kurt\Modules\I18n\Exceptions\TranslationPathException;
use Kurt\Modules\I18n\Exceptions\TranslatorNotConfiguredException;
use Kurt\Modules\I18n\Support\LangPaths;
use Kurt\Modules\I18n\Support\TranslationManager;

/**
 * Module base controller for the i18n REST API.
 *
 * Extends the Core API kit's controller so every endpoint shares the
 * `{ "data": ..., "meta": ... }` success envelope and the `{ "message": ...,
 * "errors": ... }` error envelope, and layers on the translation-specific
 * request helpers used across the resource controllers.
 */
abstract class ApiController extends CoreApiController
{
    /**
     * Parse and validate the `?locales=a,b,c` query parameter. Falls back to
     * every known locale when none are requested.
     *
     * @return list<string>
     */
    protected function localesFromRequest(Request $request, TranslationManager $manager): array
    {
        $raw = trim((string) $request->query('locales', ''));

        if ($raw === '') {
            return $manager->catalog()->locales;
        }

        $locales = LangPaths::parseLocaleList($raw);

        foreach ($locales as $locale) {
            abort_unless(LangPaths::isValidLocale($locale), 422, "Invalid locale [{$locale}].");
        }

        return $locales;
    }

    /**
     * Parse `?locales=a,b,c` when present, else return null (the caller then
     * applies its own default). Every provided locale is validated.
     *
     * @return list<string>|null
     */
    protected function optionalLocalesFromRequest(Request $request): ?array
    {
        $raw = trim((string) $request->query('locales', ''));

        if ($raw === '') {
            return null;
        }

        $locales = LangPaths::parseLocaleList($raw);

        foreach ($locales as $locale) {
            abort_unless(LangPaths::isValidLocale($locale), 422, "Invalid locale [{$locale}].");
        }

        return $locales;
    }

    /**
     * Run an apply() call and translate domain exceptions into the API error
     * envelope, preserving the write path's 409 (conflict) / 501 (translator not
     * configured) / 422 (bad path or argument) semantics.
     *
     * @param  Closure(): array{hashes: array<string, string|null>, changed: list<string>}  $apply
     */
    protected function save(Closure $apply): JsonResponse
    {
        try {
            return $this->respond($apply());
        } catch (TranslationConflictException $e) {
            return $this->fail('conflict', 409, ['locales' => $e->locales]);
        } catch (TranslatorNotConfiguredException $e) {
            return $this->fail($e->getMessage(), 501);
        } catch (TranslationPathException|InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
