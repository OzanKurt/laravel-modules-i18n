<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;

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
    /** Recognised as a plain call, on top of whatever the application configures. */
    private const ALWAYS = ['__', 'trans', 'trans_choice'];

    /**
     * Recognised only when the receiver is the translator itself.
     *
     * `get` and `choice` are far too common on other objects (`$request->get()`
     * above all) to be counted on their own.
     */
    private const TRANSLATOR_ONLY = ['get', 'choice'];

    /** Token types that can carry the name of a called function or method. */
    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

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

        // To PHP's tokeniser a Blade file is almost entirely inline HTML, so
        // `{{ __('x') }}` is never seen. Compiling first turns every directive
        // and echo into real PHP, which also means Laravel's own compiler
        // decides what a directive means instead of us maintaining a list.
        if (str_ends_with($absolutePath, '.blade.php')) {
            $source = Blade::compileString($source);
        }

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

            if (! is_array($token) || ! in_array($token[0], self::NAME_TOKENS, true)) {
                continue;
            }

            $name = $this->lastSegment($token[1]);
            $plain = in_array($name, $methods, true);

            if (! $plain && ! in_array($name, self::TRANSLATOR_ONLY, true)) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $i + 1);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            // A bare `->get(...)` is not a translation call at all, so it must
            // not even be recorded as dynamic.
            if (! $plain && ! $this->hasTranslatorReceiver($tokens, $i)) {
                continue;
            }

            $arg = $this->nextMeaningful($tokens, $open + 1);

            if ($arg === null) {
                continue;
            }

            $usages[] = $this->usageFor($tokens, $arg, $name, $file, $token[2]);
        }

        return $usages;
    }

    /** The name as written after any namespace qualifier. */
    private function lastSegment(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    /**
     * Is the call receiver `Lang::` or `app('translator')->`?
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function hasTranslatorReceiver(array $tokens, int $name): bool
    {
        $receiver = $this->previousMeaningful($tokens, $name - 1);

        if ($receiver === null || ! is_array($tokens[$receiver])) {
            return false;
        }

        return match ($tokens[$receiver][0]) {
            T_DOUBLE_COLON => $this->isLangClass($tokens, $receiver),
            T_OBJECT_OPERATOR => $this->isTranslatorInstance($tokens, $receiver),
            default => false,
        };
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function isLangClass(array $tokens, int $doubleColon): bool
    {
        $class = $this->previousMeaningful($tokens, $doubleColon - 1);

        if ($class === null) {
            return false;
        }

        $token = $tokens[$class];

        return is_array($token)
            && in_array($token[0], self::NAME_TOKENS, true)
            && $this->lastSegment($token[1]) === 'Lang';
    }

    /**
     * Walks `app ( 'translator' ) ->` backwards from the object operator.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function isTranslatorInstance(array $tokens, int $objectOperator): bool
    {
        $close = $this->previousMeaningful($tokens, $objectOperator - 1);

        if ($close === null || $tokens[$close] !== ')') {
            return false;
        }

        $argument = $this->previousMeaningful($tokens, $close - 1);

        if ($argument === null) {
            return false;
        }

        $token = $tokens[$argument];

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || substr($token[1], 1, -1) !== 'translator') {
            return false;
        }

        $open = $this->previousMeaningful($tokens, $argument - 1);

        if ($open === null || $tokens[$open] !== '(') {
            return false;
        }

        $function = $this->previousMeaningful($tokens, $open - 1);

        if ($function === null) {
            return false;
        }

        $token = $tokens[$function];

        return is_array($token)
            && in_array($token[0], self::NAME_TOKENS, true)
            && $this->lastSegment($token[1]) === 'app';
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function previousMeaningful(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private function isSkippable(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function usageFor(array $tokens, int $arg, string $method, string $file, int $line): Usage
    {
        $token = $tokens[$arg];

        // A plain single- or double-quoted string with no interpolation arrives
        // as one T_CONSTANT_ENCAPSED_STRING, and it is only the whole argument
        // when the next token closes it. Anything else (a variable, an
        // interpolated string, a concatenation) is not something we can read.
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $this->endsArgument($tokens, $arg + 1)) {
            return new Usage($this->unescape($token[1]), $file, $line, $method, true);
        }

        return new Usage('', $file, $line, $method, false);
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function endsArgument(array $tokens, int $from): bool
    {
        $next = $this->nextMeaningful($tokens, $from);

        return $next !== null && ($tokens[$next] === ')' || $tokens[$next] === ',');
    }

    /**
     * Reads a quoted literal the way PHP itself would.
     *
     * The quote character decides the rules: a single-quoted string honours
     * only `\\` and `\'`, so `'C:\path\to\file'` must survive untouched.
     */
    private function unescape(string $literal): string
    {
        $body = substr($literal, 1, -1);

        if (str_starts_with($literal, "'")) {
            return preg_replace('/\\\\([\\\\\'])/', '$1', $body) ?? $body;
        }

        return preg_replace_callback(
            '/\\\\(?:u\{([0-9A-Fa-f]+)\}|x([0-9A-Fa-f]{1,2})|([0-7]{1,3})|(.))/s',
            fn (array $match): string => $this->unescapeSequence($match),
            $body,
        ) ?? $body;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function unescapeSequence(array $match): string
    {
        if (($match[1] ?? '') !== '') {
            return mb_chr((int) hexdec($match[1]), 'UTF-8') ?: $match[0];
        }

        if (($match[2] ?? '') !== '') {
            return chr((int) hexdec($match[2]));
        }

        if (($match[3] ?? '') !== '') {
            return chr((int) octdec($match[3]) % 256);
        }

        // PHP keeps the backslash in front of anything it does not recognise.
        return match ($match[4] ?? '') {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            'v' => "\v",
            'e' => "\e",
            'f' => "\f",
            '\\' => '\\',
            '$' => '$',
            '"' => '"',
            default => $match[0],
        };
    }
}
