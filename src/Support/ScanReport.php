<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Kurt\Modules\I18n\Enums\FileType;

/**
 * Combines what code uses with what the files hold.
 *
 * Four categories, deliberately separate. `dynamic` means the key could not be
 * read at all; `ambiguous` means it was read but belongs nowhere we can name.
 * The developer's next action differs, so merging them would leave both
 * unactionable.
 */
class ScanReport
{
    public function __construct(
        private readonly SourceScanner $scanner,
        private readonly TranslationManager $manager,
        private readonly Repository $config,
    ) {}

    /**
     * @param  list<string>|null  $locales  the locales to check for missing keys; null or an empty list means every locale on disk
     * @return array{locales: list<string>, missing: array<string, list<string>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
     */
    public function generate(?array $locales = null): array
    {
        $scan = $this->scanner->scan();
        $warnings = $scan['warnings'];
        $catalog = $this->manager->catalog();

        // An empty list carries no information about which locales the caller
        // cares about, and answering it literally would report an empty
        // `missing` for a catalogue that may be full of gaps. Treat it as the
        // absent filter it is, and say so through the returned `locales`.
        $locales = $locales === null || $locales === [] ? $catalog->locales : $locales;

        $stored = $this->storedKeys($locales, $catalog->locales);
        $resolver = new KeyResolver($catalog, static fn (string $key): bool => isset($stored['json'][$key]));

        $dynamic = [];
        $ambiguous = [];
        $codeKeys = [];

        foreach ($scan['usages'] as $usage) {
            if (! $usage->isLiteral) {
                $dynamic[] = ['file' => $usage->file, 'line' => $usage->line, 'method' => $usage->method];

                continue;
            }

            if ($resolver->resolve($usage->key)['store'] === 'ambiguous') {
                $ambiguous[] = ['key' => $usage->key, 'file' => $usage->file, 'line' => $usage->line];
            }

            $codeKeys[$usage->key] = true;
        }

        $missing = [];

        foreach ($locales as $locale) {
            $absent = array_values(array_filter(
                array_keys($codeKeys),
                fn (string $key): bool => ! isset($stored['byLocale'][$locale][$key]),
            ));

            if ($absent !== []) {
                $missing[$locale] = $absent;
            }
        }

        if ($codeKeys === []) {
            // Zero literal usages is always misconfiguration, never truth, and
            // publishing "everything is unused" invites someone to delete their
            // whole catalogue.
            $warnings[] = ['file' => '', 'reason' => 'No literal translation calls were found; check i18n.scan.paths.'];

            return $this->result($locales, $missing, [], $dynamic, $ambiguous, $warnings);
        }

        $unused = array_values(array_filter(
            array_keys($stored['all']),
            fn (string $key): bool => ! isset($codeKeys[$key]) && ! $this->isIgnored($key),
        ));

        return $this->result($locales, $missing, $unused, $dynamic, $ambiguous, $warnings);
    }

    /**
     * @param  list<string>  $requested  the locales `missing` is computed for
     * @param  list<string>  $known  every locale the catalogue holds
     * @return array{all: array<string, true>, json: array<string, true>, byLocale: array<string, array<string, true>>}
     */
    private function storedKeys(array $requested, array $known): array
    {
        $all = [];
        $json = [];
        $byLocale = array_fill_keys($requested, []);

        // groups() already yields the JSON pseudo-group (group === null) and
        // every vendor group as "package::group", so iterating it is the whole
        // set. Prefixing by the group name reproduces the key form the resolver
        // works with: "auth.failed", "somepkg::messages.hello".
        foreach ($this->manager->groups() as $source) {
            $type = $source['type'];
            $group = $source['group'];

            // Read every locale the catalogue knows, never just the requested
            // subset. `all` feeds `unused` and `json` feeds the resolver's JSON
            // test, and both categories are locale-independent by design: a key
            // that only "en" defines is still defined when the caller asks
            // about "tr". Only `byLocale` narrows to the requested locales.
            $grid = $this->manager->grid($type, $group, $known);
            $prefix = $group !== null ? $group.'.' : '';

            foreach ($grid['keys'] as $key) {
                $full = $prefix.$key;
                $all[$full] = true;

                if ($type === FileType::Json) {
                    $json[$full] = true;
                }

                foreach ($requested as $locale) {
                    // A requested locale the catalogue does not know has no row
                    // here, so every key counts as absent for it — which is
                    // exactly right for a locale with no files on disk.
                    if (($grid['rows'][$key][$locale] ?? null) !== null) {
                        $byLocale[$locale][$full] = true;
                    }
                }
            }
        }

        return ['all' => $all, 'json' => $json, 'byLocale' => $byLocale];
    }

    private function isIgnored(string $key): bool
    {
        /** @var list<string> $groups */
        $groups = $this->config->get('i18n.scan.ignored_groups', []);
        /** @var list<string> $keys */
        $keys = $this->config->get('i18n.scan.ignored_keys', []);

        if (in_array($key, $keys, true)) {
            return true;
        }

        if (str_contains($key, '::')) {
            return true;
        }

        foreach ($groups as $group) {
            if (str_starts_with($key, $group.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $locales
     * @param  array<string, list<string>>  $missing
     * @param  list<string>  $unused
     * @param  list<array{file: string, line: int, method: string}>  $dynamic
     * @param  list<array{key: string, file: string, line: int}>  $ambiguous
     * @param  list<array{file: string, reason: string}>  $warnings
     * @return array{locales: list<string>, missing: array<string, list<string>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
     */
    private function result(array $locales, array $missing, array $unused, array $dynamic, array $ambiguous, array $warnings): array
    {
        return [
            'locales' => $locales,
            'missing' => $missing,
            'unused' => $unused,
            'dynamic' => $dynamic,
            'ambiguous' => $ambiguous,
            'warnings' => $warnings,
        ];
    }
}
