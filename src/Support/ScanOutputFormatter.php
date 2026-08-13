<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

/**
 * Turns a scan report into something printable.
 *
 * Pure on purpose: no container, no filesystem, no Laravel Command. The
 * presentation rules are worth testing on their own, and the dashboard will
 * want the same transformation.
 *
 * @phpstan-type MissingKey array{key: string, store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, package: string|null}
 * @phpstan-type Report array{locales: list<string>, missing: array<string, list<MissingKey>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
 * @phpstan-type Section array{title: string, headers: list<string>, rows: list<list<string>>}
 */
final class ScanOutputFormatter
{
    /** @var list<string> */
    public const CATEGORIES = ['missing', 'unused', 'dynamic', 'ambiguous'];

    /**
     * @param  Report  $report
     */
    public static function json(array $report): string
    {
        return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Report  $report
     * @param  list<string>  $categories
     * @return list<Section>
     */
    public static function sections(array $report, array $categories): array
    {
        $sections = [];

        if (in_array('missing', $categories, true)) {
            foreach ($report['missing'] as $locale => $keys) {
                if ($keys === []) {
                    continue;
                }

                $sections[] = [
                    'title' => "missing ({$locale})",
                    'headers' => ['key', 'store', 'group'],
                    'rows' => array_map(
                        static fn (array $k): array => [$k['key'], $k['store'], self::groupCell($k['group'], $k['package'])],
                        $keys,
                    ),
                ];
            }
        }

        if (in_array('unused', $categories, true) && $report['unused'] !== []) {
            $sections[] = [
                'title' => 'unused',
                'headers' => ['key'],
                'rows' => array_map(static fn (string $k): array => [$k], $report['unused']),
            ];
        }

        if (in_array('dynamic', $categories, true) && $report['dynamic'] !== []) {
            $sections[] = [
                'title' => 'dynamic',
                'headers' => ['file', 'line', 'method'],
                'rows' => array_map(
                    static fn (array $d): array => [$d['file'], (string) $d['line'], $d['method']],
                    $report['dynamic'],
                ),
            ];
        }

        if (in_array('ambiguous', $categories, true) && $report['ambiguous'] !== []) {
            $sections[] = [
                'title' => 'ambiguous',
                'headers' => ['key', 'file', 'line'],
                'rows' => array_map(
                    static fn (array $a): array => [$a['key'], $a['file'], (string) $a['line']],
                    $report['ambiguous'],
                ),
            ];
        }

        return $sections;
    }

    private static function groupCell(?string $group, ?string $package): string
    {
        if ($group === null) {
            return '-';
        }

        return $package !== null ? "{$package}::{$group}" : $group;
    }
}
