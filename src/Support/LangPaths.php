<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Kurt\Modules\I18n\Exceptions\TranslationPathException;

/**
 * Resolves and guards every translation file path.
 *
 * All paths are derived from a single root directory; locale and group names are
 * validated against a strict character set and the resulting absolute path is
 * verified to live inside the root, so path traversal is structurally impossible.
 */
final class LangPaths
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = $this->normalize($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function jsonPath(string $locale): string
    {
        $this->guardLocale($locale);

        return $this->within($locale.'.json');
    }

    public function phpPath(string $group, string $locale): string
    {
        $this->guardLocale($locale);

        // Namespaced vendor group "{package}::{group}" maps to
        // lang/vendor/{package}/{locale}/{group}.php (Laravel's package translations).
        if (str_contains($group, '::')) {
            [$package, $inner] = explode('::', $group, 2);
            $this->guardPackage($package);
            $this->guardGroup($inner);

            return $this->within('vendor/'.$package.'/'.$locale.'/'.$inner.'.php');
        }

        $this->guardGroup($group);

        return $this->within($locale.'/'.$group.'.php');
    }

    public function assertSafe(string $absolute): void
    {
        $normalized = $this->normalize($absolute);

        if ($normalized !== $this->root && ! str_starts_with($normalized, $this->root.'/')) {
            throw TranslationPathException::outsideRoot($absolute);
        }
    }

    public function relative(string $absolute): string
    {
        $this->assertSafe($absolute);

        return ltrim(substr($this->normalize($absolute), strlen($this->root)), '/');
    }

    public static function isValidLocale(string $locale): bool
    {
        return $locale !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $locale) === 1;
    }

    /**
     * Split a comma-separated locale list into trimmed, unique, non-empty
     * entries, so `?locales=` and `--locales=` agree on what a list means.
     *
     * Validation is deliberately left to the caller: the HTTP layer aborts 422
     * on a bad locale and the console layer reports a usage error, and only the
     * parsing and deduplication are worth sharing.
     *
     * @return list<string>
     */
    public static function parseLocaleList(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $locale): bool => $locale !== '',
        )));
    }

    public static function isValidGroup(string $group): bool
    {
        if ($group === '' || str_contains($group, '\\')) {
            return false;
        }

        foreach (explode('/', $group) as $segment) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function isValidPackage(string $package): bool
    {
        return $package !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $package) === 1;
    }

    /** Accepts a plain group ("auth", "admin/users") or a namespaced one ("firewall::notifications"). */
    public static function isValidGroupRef(string $ref): bool
    {
        if (str_contains($ref, '::')) {
            [$package, $inner] = explode('::', $ref, 2);

            return self::isValidPackage($package) && self::isValidGroup($inner);
        }

        return self::isValidGroup($ref);
    }

    private function guardLocale(string $locale): void
    {
        if (! self::isValidLocale($locale)) {
            throw TranslationPathException::invalidLocale($locale);
        }
    }

    private function guardGroup(string $group): void
    {
        if (! self::isValidGroup($group)) {
            throw TranslationPathException::invalidGroup($group);
        }
    }

    private function guardPackage(string $package): void
    {
        if (! self::isValidPackage($package)) {
            throw TranslationPathException::invalidGroup($package);
        }
    }

    private function within(string $relative): string
    {
        $candidate = $this->normalize($this->root.'/'.$relative);

        $this->assertSafe($candidate);

        return $candidate;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('#^[A-Za-z]:/#', $path) === 1;

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $joined = implode('/', $segments);

        if ($isUnixAbsolute && ! $isWindowsAbsolute) {
            $joined = '/'.$joined;
        }

        return rtrim($joined, '/');
    }
}
