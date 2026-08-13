<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\ScanReport;

/**
 * Read-only report of how source code lines up with the translation files.
 *
 * All four categories come from one scan, so they are returned together rather
 * than split across endpoints that would each repeat the work.
 */
final class ScanReportController extends ApiController
{
    public function __invoke(Request $request, ScanReport $report, ScanCache $cache): JsonResponse
    {
        if ($request->boolean('refresh')) {
            $cache->flush();
        }

        return $this->respond($report->generate($this->optionalLocalesFromRequest($request)));
    }
}
