<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

/**
 * One translation call site found in source.
 *
 * A non-literal call (for example `__($key)`) carries an empty key and
 * `isLiteral = false`. Those are reported as `dynamic` rather than guessed at.
 */
final readonly class Usage
{
    public function __construct(
        public string $key,
        public string $file,
        public int $line,
        public string $method,
        public bool $isLiteral,
    ) {}
}
