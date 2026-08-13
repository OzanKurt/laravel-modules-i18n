<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Closure;

/**
 * Places a key found in source against what actually exists on disk.
 *
 * Evidence decides, not a guess: a dotted key is a group key only when that
 * group really exists. A dotted key matching neither a group nor a JSON entry
 * is reported as ambiguous, because writing it to the wrong store would send
 * the developer to the wrong file.
 */
final readonly class KeyResolver
{
    private Closure $jsonHas;

    public function __construct(
        private TranslationCatalog $catalog,
        callable $jsonHas,
    ) {
        $this->jsonHas = $jsonHas(...);
    }

    /**
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    public function resolve(string $key): array
    {
        if (str_contains($key, '::')) {
            return $this->resolveVendor($key);
        }

        // A group key must be "group.item", so a dotless key can only be JSON.
        if (! str_contains($key, '.')) {
            return $this->result('json');
        }

        foreach ($this->groupCandidates($key) as [$group, $item]) {
            if (in_array($group, $this->catalog->phpGroups, true)) {
                return $this->result('group', group: $group, item: $item);
            }
        }

        if (($this->jsonHas)($key)) {
            return $this->result('json');
        }

        return $this->result('ambiguous');
    }

    /**
     * Every dot position, rightmost first, so "admin/users.title" prefers the
     * "admin/users" group over a narrower split that does not exist.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function groupCandidates(string $key): array
    {
        $positions = [];
        $offset = 0;

        while (($dot = strpos($key, '.', $offset)) !== false) {
            $positions[] = $dot;
            $offset = $dot + 1;
        }

        $candidates = [];

        foreach (array_reverse($positions) as $dot) {
            $candidates[] = [substr($key, 0, $dot), substr($key, $dot + 1)];
        }

        return $candidates;
    }

    /**
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    private function resolveVendor(string $key): array
    {
        [$package, $rest] = explode('::', $key, 2);

        if (! str_contains($rest, '.')) {
            return $this->result('ambiguous');
        }

        foreach ($this->catalog->vendor as $vendor) {
            if ($vendor['name'] !== $package) {
                continue;
            }

            foreach ($this->groupCandidates($rest) as [$group, $item]) {
                if (in_array($group, $vendor['groups'], true)) {
                    return $this->result('vendor', group: $group, item: $item, package: $package);
                }
            }
        }

        return $this->result('ambiguous');
    }

    /**
     * @param  'json'|'group'|'vendor'|'ambiguous'  $store
     * @return array{store: 'json'|'group'|'vendor'|'ambiguous', group: string|null, item: string|null, package: string|null}
     */
    private function result(string $store, ?string $group = null, ?string $item = null, ?string $package = null): array
    {
        return ['store' => $store, 'group' => $group, 'item' => $item, 'package' => $package];
    }
}
