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
 *
 * @phpstan-type MissingKey array{key: string, store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, package: string|null}
 */
final readonly class ScanReport
{
    /** The warning a walk that read no literal call site at all carries. */
    public const NO_USAGES_WARNING = 'No literal translation calls were found; check i18n.scan.paths.';

    public function __construct(
        private SourceScanner $scanner,
        private TranslationManager $manager,
        private Repository $config,
    ) {}

    /**
     * @param  list<string>|null  $locales  the locales to check for missing keys; null or an empty list means every locale on disk
     * @return array{locales: list<string>, missing: array<string, list<MissingKey>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
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

            $resolved = $resolver->resolve($usage->key);

            if ($resolved['store'] === 'ambiguous') {
                $ambiguous[] = ['key' => $usage->key, 'file' => $usage->file, 'line' => $usage->line];
            }

            // The resolution is kept, not thrown away after the ambiguity test:
            // `missing` reports it, so a consumer can tell an unplaceable key
            // from one that simply has no value yet without cross-referencing
            // `ambiguous` by string.
            $codeKeys[$usage->key] = $resolved;
        }

        $missing = [];

        foreach ($locales as $locale) {
            $absent = [];

            foreach ($codeKeys as $key => $resolved) {
                if (isset($stored['byLocale'][$locale][$key])) {
                    continue;
                }

                // A numeric-looking key comes back from the array as an int.
                $absent[] = [
                    'key' => (string) $key,
                    'store' => $resolved['store'],
                    'group' => $resolved['group'],
                    'package' => $resolved['package'],
                ];
            }

            if ($absent !== []) {
                $missing[$locale] = $absent;
            }
        }

        if ($codeKeys === []) {
            // Zero literal usages is always misconfiguration, never truth, and
            // publishing "everything is unused" invites someone to delete their
            // whole catalogue.
            $warnings[] = ['file' => '', 'reason' => self::NO_USAGES_WARNING];

            return $this->result($locales, $missing, [], $dynamic, $ambiguous, $warnings);
        }

        if ($scan['warnings'] !== []) {
            // The same reasoning as the guard above, one file at a time. A key
            // called only from a file that failed to scan looks exactly like a
            // key nothing calls, so publishing `unused` after a partial walk
            // invites someone to delete a key the application uses at runtime.
            // Withholding it is all-or-nothing because the walk is: there is no
            // way to tell which of the surviving "unused" keys the failed files
            // would have vouched for.
            $warnings[] = ['file' => '', 'reason' => 'Some files could not be scanned, so unused was withheld; a key used only in a file that failed would look unused.'];

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
                    // here, so every key counts as absent for it, which is
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
     * @param  array<string, list<MissingKey>>  $missing
     * @param  list<string>  $unused
     * @param  list<array{file: string, line: int, method: string}>  $dynamic
     * @param  list<array{key: string, file: string, line: int}>  $ambiguous
     * @param  list<array{file: string, reason: string}>  $warnings
     * @return array{locales: list<string>, missing: array<string, list<MissingKey>>, unused: list<string>, dynamic: list<array{file: string, line: int, method: string}>, ambiguous: list<array{key: string, file: string, line: int}>, warnings: list<array{file: string, reason: string}>}
     */
    private function result(array $locales, array $missing, array $unused, array $dynamic, array $ambiguous, array $warnings): array
    {
        return [
            'locales' => $locales,
            'missing' => $missing,
            'unused' => $unused,
            'dynamic' => array_map(fn (array $row): array => [...$row, 'file' => $this->relative($row['file'])], $dynamic),
            'ambiguous' => array_map(fn (array $row): array => [...$row, 'file' => $this->relative($row['file'])], $ambiguous),
            'warnings' => array_map(fn (array $row): array => [...$row, 'file' => $this->relative($row['file'])], $warnings),
        ];
    }

    /**
     * A reported path, written against the application root where it can be.
     *
     * The scanner works in absolute paths because that is the only way to
     * decide containment, but a report is read by a human or a UI, and
     * "app/Http/Controllers/HomeController.php" is what either wants. It also
     * keeps the endpoint from publishing the server's deployment layout to
     * everyone allowed to read a scan.
     *
     * A file genuinely outside the base path keeps its absolute form, because a
     * relative path that has to climb out of the root would be worse than no
     * relativising at all. The empty string is what the report's own warnings
     * carry when they blame no file in particular.
     */
    private function relative(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $normalised = str_replace('\\', '/', $path);

        if ($base !== '' && str_starts_with($normalised, $base.'/')) {
            return substr($normalised, strlen($base) + 1);
        }

        return $path;
    }
}
