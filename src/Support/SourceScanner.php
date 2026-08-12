<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

/**
 * Finds translation call sites in source.
 *
 * PHP's tokeniser is used rather than a regular expression so that a call
 * written inside a comment, a string literal or a docblock is never mistaken
 * for a real one. A call whose first argument is not a plain string is
 * recorded with `isLiteral = false` and reported as dynamic, never guessed at.
 */
class SourceScanner
{
    /** Always recognised, on top of whatever the application configures. */
    private const ALWAYS = ['__', 'trans', 'trans_choice', 'get', 'choice'];

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        /** @var list<string> $configured */
        $configured = $this->config->get('i18n.scan.methods', []);

        return array_values(array_unique([...self::ALWAYS, ...$configured]));
    }

    /**
     * @return list<Usage>
     */
    public function scanFile(string $absolutePath): array
    {
        $source = (string) $this->files->get($absolutePath);

        return $this->tokenize($source, $absolutePath);
    }

    /**
     * @return list<Usage>
     */
    private function tokenize(string $php, string $file): array
    {
        $tokens = token_get_all($php);
        $methods = $this->methods();
        $usages = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array($token[1], $methods, true)) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $i + 1);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $arg = $this->nextMeaningful($tokens, $open + 1);

            if ($arg === null) {
                continue;
            }

            $usages[] = $this->usageFor($tokens[$arg], $token[1], $file, $token[2]);
        }

        return $usages;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $arg
     */
    private function usageFor(array|string $arg, string $method, string $file, int $line): Usage
    {
        // A plain single- or double-quoted string with no interpolation arrives
        // as one T_CONSTANT_ENCAPSED_STRING. Anything else (a variable, an
        // interpolated string, a concatenation) is not something we can read.
        if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
            $key = substr($arg[1], 1, -1);
            $key = stripcslashes($key);

            return new Usage($key, $file, $line, $method, true);
        }

        return new Usage('', $file, $line, $method, false);
    }
}
