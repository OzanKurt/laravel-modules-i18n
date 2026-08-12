<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/**
 * Fingerprint cache for scan results.
 *
 * Entries are keyed on path, mtime and size, and the whole file is keyed on a
 * hash of the scan configuration. That second half matters: adding a function
 * to `scan.methods` changes no file, so without it the new function would never
 * be scanned and the report would be quietly wrong.
 *
 * The cache is an optimisation, never a source of truth. Nothing it does may
 * change what a scan reports and no trouble it runs into may cost a real
 * result, so a cache file that cannot be read, does not parse, does not carry
 * the shape this version writes, or cannot be written at all is given up on in
 * silence. The cost of that is one rescan; the cost of raising would be a
 * report with a hole in it.
 */
class ScanCache
{
    /**
     * The shape of what `put()` writes.
     *
     * The config fingerprint answers "was this scanned under the settings in
     * force now?", which says nothing about whether the entries themselves can
     * still be read back. An upgrade that changes `Usage` leaves both the files
     * and the configuration untouched, so only a version stamped into the file
     * can tell the new reader that the old entries are not its own.
     */
    private const FORMAT = 1;

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<Usage>|null
     */
    public function get(string $file, int $mtime, int $size): ?array
    {
        if ($this->config->get('i18n.scan.cache', true) !== true) {
            return null;
        }

        $entry = $this->read()['files'][$file] ?? null;

        if (! is_array($entry) || ($entry['mtime'] ?? null) !== $mtime || ($entry['size'] ?? null) !== $size) {
            return null;
        }

        $rows = $entry['usages'] ?? null;

        if (! is_array($rows)) {
            return null;
        }

        $usages = [];

        foreach ($rows as $row) {
            $usage = $this->usageFrom($row);

            // One unreadable row makes the whole entry untrustworthy: a partial
            // list would silently drop keys the file really uses, which is the
            // one thing a cache must never do. Rescanning the file is free by
            // comparison.
            if ($usage === null) {
                return null;
            }

            $usages[] = $usage;
        }

        return $usages;
    }

    /**
     * Rebuilds one stored usage, or reports that it cannot be trusted.
     *
     * Every field is checked rather than assumed. `Usage` is strict about its
     * types, so a row that decoded with, say, a null line would raise a
     * TypeError halfway through building the list.
     */
    private function usageFrom(mixed $row): ?Usage
    {
        if (! is_array($row)) {
            return null;
        }

        $key = $row['key'] ?? null;
        $file = $row['file'] ?? null;
        $line = $row['line'] ?? null;
        $method = $row['method'] ?? null;
        $isLiteral = $row['isLiteral'] ?? null;

        if (! is_string($key) || ! is_string($file) || ! is_int($line) || ! is_string($method) || ! is_bool($isLiteral)) {
            return null;
        }

        return new Usage($key, $file, $line, $method, $isLiteral);
    }

    /**
     * @param  list<Usage>  $usages
     */
    public function put(string $file, int $mtime, int $size, array $usages): void
    {
        if ($this->config->get('i18n.scan.cache', true) !== true) {
            return;
        }

        try {
            $data = $this->read();
            $data['files'][$file] = [
                'mtime' => $mtime,
                'size' => $size,
                'usages' => array_map(static fn (Usage $u): array => [
                    'key' => $u->key,
                    'file' => $u->file,
                    'line' => $u->line,
                    'method' => $u->method,
                    'isLiteral' => $u->isLiteral,
                ], $usages),
            ];

            $this->write($data);
        } catch (Throwable) {
            // A cache directory that cannot be created, a disk with nothing
            // left on it, or source that is not valid UTF-8 and so cannot be
            // encoded: none of that says anything about the scan that just
            // succeeded. Caching this entry is abandoned and the caller is
            // never told, because there is nothing for it to do about it. The
            // caller merging its findings before calling this is what keeps
            // the result safe; refusing to throw is what keeps it safe when a
            // later caller stops doing that.
        }
    }

    public function flush(): void
    {
        $path = $this->path();

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    private function path(): string
    {
        /** @var string|null $configured */
        $configured = $this->config->get('i18n.scan.cache_path');

        return $configured ?? storage_path('framework/cache/i18n-scan.json');
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode($this->config->get('i18n.scan'), JSON_THROW_ON_ERROR));
    }

    /**
     * The stored entries, or an empty set whenever they cannot be trusted.
     *
     * Only the envelope is settled here: that the file parses, was written by
     * this version of the format, was written under the configuration in force
     * now, and holds a map of entries at all. What each entry contains is not
     * asserted, because a claim made here would be a claim about a decoded JSON
     * document nobody has looked at yet. {@see get()} checks a row before it
     * trusts one.
     *
     * @return array{version: int, config: string, files: array<array-key, mixed>}
     */
    private function read(): array
    {
        $empty = ['version' => self::FORMAT, 'config' => $this->fingerprint(), 'files' => []];
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return $empty;
        }

        try {
            $decoded = json_decode((string) $this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::FORMAT) {
            return $empty;
        }

        if (($decoded['config'] ?? null) !== $this->fingerprint()) {
            return $empty;
        }

        $files = $decoded['files'] ?? null;

        if (! is_array($files)) {
            return $empty;
        }

        return ['version' => self::FORMAT, 'config' => $this->fingerprint(), 'files' => $files];
    }

    /**
     * @param  array{version: int, config: string, files: array<array-key, mixed>}  $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
