<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Fingerprint cache for scan results.
 *
 * Entries are keyed on path, mtime and size, and the whole file is keyed on a
 * hash of the scan configuration. That second half matters: adding a function
 * to `scan.methods` changes no file, so without it the new function would never
 * be scanned and the report would be quietly wrong.
 *
 * The cache is an optimisation, never a source of truth, so an unreadable or
 * corrupt cache file is discarded rather than raised.
 */
class ScanCache
{
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

        $data = $this->read();
        $entry = $data['files'][$file] ?? null;

        if ($entry === null || $entry['mtime'] !== $mtime || $entry['size'] !== $size) {
            return null;
        }

        return array_map(
            static fn (array $u): Usage => new Usage($u['key'], $u['file'], $u['line'], $u['method'], $u['isLiteral']),
            $entry['usages'],
        );
    }

    /**
     * @param  list<Usage>  $usages
     */
    public function put(string $file, int $mtime, int $size, array $usages): void
    {
        if ($this->config->get('i18n.scan.cache', true) !== true) {
            return;
        }

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
     * @return array{config: string, files: array<string, array{mtime: int, size: int, usages: list<array{key: string, file: string, line: int, method: string, isLiteral: bool}>}>}
     */
    private function read(): array
    {
        $empty = ['config' => $this->fingerprint(), 'files' => []];
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return $empty;
        }

        try {
            /** @var array{config?: string, files?: array<string, mixed>} $decoded */
            $decoded = json_decode((string) $this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }

        if (($decoded['config'] ?? null) !== $this->fingerprint()) {
            return $empty;
        }

        /** @var array{config: string, files: array<string, array{mtime: int, size: int, usages: list<array{key: string, file: string, line: int, method: string, isLiteral: bool}>}>} $decoded */
        return $decoded;
    }

    /**
     * @param  array{config: string, files: array<string, mixed>}  $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
