<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Console\Commands;

use Illuminate\Console\Command;
use Kurt\Modules\I18n\Support\LangPaths;
use Kurt\Modules\I18n\Support\ScanCache;
use Kurt\Modules\I18n\Support\ScanOutputFormatter;
use Kurt\Modules\I18n\Support\ScanReport;

/**
 * Read-only report of how source code lines up with the translation files.
 *
 * Deliberately thin: every rule about what the categories mean, and when
 * `unused` may be trusted, lives in ScanReport. This class only turns flags
 * into a call, output into text, and outcomes into an exit code.
 *
 * It calls ScanReport directly rather than the HTTP endpoint, so a CI run needs
 * no web server.
 *
 * @phpstan-import-type Report from ScanOutputFormatter
 */
final class ScanCommand extends Command
{
    private const EXIT_CLEAN = 0;

    private const EXIT_FINDINGS = 1;

    private const EXIT_INCOMPLETE = 2;

    private const EXIT_USAGE = 64;

    /** Categories --fail gates on when --only was not given. */
    private const FAIL_BY_DEFAULT = ['missing', 'unused', 'ambiguous'];

    protected $signature = 'i18n:scan
        {--only= : Comma-separated categories: missing, unused, dynamic, ambiguous}
        {--locales= : Comma-separated locales; narrows missing}
        {--format=table : table or json}
        {--fail : Exit non-zero when a selected category holds findings}
        {--refresh : Bypass the scan cache}';

    protected $description = 'Report translation keys used in code but missing from files, and keys stored but unused.';

    public function handle(ScanReport $report, ScanCache $cache): int
    {
        $categories = $this->categories();

        if ($categories === null) {
            return self::EXIT_USAGE;
        }

        $locales = $this->locales();

        if ($locales === false) {
            return self::EXIT_USAGE;
        }

        if ($this->option('refresh')) {
            $cache->flush();
        }

        $result = $report->generate($locales);

        if ($this->option('format') === 'json') {
            $this->line(ScanOutputFormatter::json($result));
        } else {
            $this->renderTables($result, $categories);
        }

        return $this->exitCode($result, $categories);
    }

    /**
     * @return list<string>|null null signals a usage error, already reported
     */
    private function categories(): ?array
    {
        $only = $this->option('only');

        if (! is_string($only) || trim($only) === '') {
            return ScanOutputFormatter::CATEGORIES;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $only))));
        $unknown = array_diff($requested, ScanOutputFormatter::CATEGORIES);

        if ($unknown !== []) {
            $this->error('Unknown category: '.implode(', ', $unknown).'. Valid: '.implode(', ', ScanOutputFormatter::CATEGORIES).'.');

            return null;
        }

        return $requested;
    }

    /**
     * @return list<string>|null|false false signals a usage error, already reported
     */
    private function locales(): array|null|false
    {
        $raw = $this->option('locales');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $locales = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($locales as $locale) {
            if (! LangPaths::isValidLocale($locale)) {
                $this->error("Invalid locale [{$locale}].");

                return false;
            }
        }

        return $locales;
    }

    /**
     * @param  Report  $result
     * @param  list<string>  $categories
     */
    private function renderTables(array $result, array $categories): void
    {
        foreach (ScanOutputFormatter::sections($result, $categories) as $section) {
            $this->newLine();
            $this->line($section['title']);
            $this->table($section['headers'], $section['rows']);
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->line('warnings');
            $this->table(
                ['file', 'reason'],
                array_map(static fn (array $w): array => [$w['file'], $w['reason']], $result['warnings']),
            );
        }
    }

    /**
     * @param  Report  $result
     * @param  list<string>  $categories
     */
    private function exitCode(array $result, array $categories): int
    {
        // An incomplete scan outranks a finding: the findings themselves came
        // from a walk with known gaps, so blaming them would overstate the run.
        if (in_array('unused', $categories, true) && $result['warnings'] !== []) {
            return self::EXIT_INCOMPLETE;
        }

        if (! $this->option('fail')) {
            return self::EXIT_CLEAN;
        }

        $gated = $this->option('only') === null || $this->option('only') === ''
            ? self::FAIL_BY_DEFAULT
            : $categories;

        foreach ($gated as $category) {
            $found = $category === 'missing'
                ? array_sum(array_map('count', $result['missing']))
                : count($result[$category]);

            if ($found > 0) {
                return self::EXIT_FINDINGS;
            }
        }

        return self::EXIT_CLEAN;
    }
}
