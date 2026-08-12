<?php

declare(strict_types=1);

namespace Kurt\Modules\I18n\Support;

use FilesystemIterator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

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
        private readonly BladeCompiler $blade,
        private readonly ScanCache $cache,
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
     * @return list<string>
     */
    public function paths(): array
    {
        /** @var list<string>|null $configured */
        $configured = $this->config->get('i18n.scan.paths');

        return $configured ?? [app_path(), resource_path()];
    }

    /**
     * @return list<string>
     */
    public function excludedPaths(): array
    {
        /** @var list<string>|null $configured */
        $configured = $this->config->get('i18n.scan.excluded_paths');

        return $configured ?? [base_path('vendor'), storage_path()];
    }

    /**
     * @return array{usages: list<Usage>, warnings: list<array{file: string, reason: string}>}
     */
    public function scan(): array
    {
        $usages = [];
        $warnings = [];

        // `paths()` cannot tell a configured root from a fallback one once it
        // has merged them into one list, so the raw config is asked directly:
        // a root the developer typed in deserves a warning when it is not
        // there, but the package's own [app_path(), resource_path()] default
        // is a guess about a shape the application may legitimately not have,
        // and a warning about it could never be resolved by the developer.
        $usingDefaultPaths = $this->config->get('i18n.scan.paths') === null;

        // Resolved once per scan and reused for every root and every file
        // below it, rather than re-resolving the same configured exclusions
        // on every single file.
        $excluded = $this->resolvedExcludedPaths();

        foreach ($this->paths() as $root) {
            if ($usingDefaultPaths && ! $this->files->isDirectory($root)) {
                continue;
            }

            $walk = $this->filesUnder($root, $excluded);
            $warnings = [...$warnings, ...$walk['warnings']];

            foreach ($walk['files'] as $file) {
                try {
                    $mtime = (int) $this->files->lastModified($file);
                    $size = (int) $this->files->size($file);
                    $cached = $this->cache->get($file, $mtime, $size);

                    if ($cached !== null) {
                        $usages = [...$usages, ...$cached];

                        continue;
                    }

                    $found = $this->scanFile($file);
                    $this->cache->put($file, $mtime, $size, $found);
                    $usages = [...$usages, ...$found];
                } catch (Throwable $e) {
                    // One malformed file must not cost the whole report; a
                    // report with a named gap beats no report at all.
                    $warnings[] = ['file' => $file, 'reason' => $e->getMessage()];
                }
            }
        }

        return ['usages' => $usages, 'warnings' => $warnings];
    }

    /**
     * Every scannable file under one configured root, and what went wrong.
     *
     * A root that cannot be opened at all, or a walk that fails part way down
     * because a directory below it is unreadable or has just been removed, is
     * named in a warning rather than thrown: the roots after it still deserve
     * a report, and a root the developer configured but that is not there is
     * a configuration mistake worth being told about. (A missing root that
     * came from the built-in default path list never reaches this method at
     * all; `scan()` skips it before the walk starts.)
     *
     * @param  list<string>  $excluded  Resolved once per scan by {@see resolvedExcludedPaths()}.
     * @return array{files: list<string>, warnings: list<array{file: string, reason: string}>}
     */
    private function filesUnder(string $root, array $excluded): array
    {
        $found = [];
        $warnings = [];

        try {
            // SKIP_DOTS keeps . and .. out of the walk.
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            $canonicalRoot = realpath($root);

            if ($canonicalRoot === false) {
                throw new RuntimeException("Unable to resolve [{$root}].");
            }

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Whether this walk steps into a symlink or a Windows junction
                // is a platform question we do not have to answer. realpath()
                // resolves links and `..` alike, so a file counts only when it
                // really lives under the configured root; anything else is not
                // ours to scan and is dropped without comment, since it is not
                // a broken file, just a path outside the job.
                $path = realpath($file->getPathname());

                if ($path === false || ! $this->isInside($path, $canonicalRoot)) {
                    continue;
                }

                if ($this->isExcluded($path, $excluded)) {
                    continue;
                }

                $found[] = $path;
            }
        } catch (Throwable $e) {
            $warnings[] = ['file' => $root, 'reason' => $e->getMessage()];
        }

        sort($found);

        return ['files' => $found, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $excluded  Already resolved by {@see resolvedExcludedPaths()}.
     */
    private function isExcluded(string $path, array $excluded): bool
    {
        foreach ($excluded as $candidate) {
            if ($this->isInside($path, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves every configured exclusion once per scan.
     *
     * A configured exclusion may be written with `..` or with the other
     * platform's separator, so it is resolved the same way a scanned path is
     * before the two are ever compared. Doing that once here, instead of
     * inside {@see isExcluded()}, means the resolution work is not repeated
     * for every single file the walk visits.
     *
     * @return list<string>
     */
    private function resolvedExcludedPaths(): array
    {
        return array_map(function (string $excluded): string {
            $resolved = realpath($excluded);

            return $resolved === false ? $excluded : $resolved;
        }, $this->excludedPaths());
    }

    /**
     * Is `$path` the directory `$root` itself or something below it?
     *
     * The comparison is on one separator, and it has to land on a path
     * boundary: `vendor` covers `vendor/one.php` but never `vendor-tools`,
     * which a plain prefix test would swallow.
     */
    private function isInside(string $path, string $root): bool
    {
        $path = $this->forwardSlashes($path);
        $root = rtrim($this->forwardSlashes($root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }

    /** Windows hands back backslashes; config is as often written with slashes. */
    private function forwardSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @return list<Usage>
     */
    public function scanFile(string $absolutePath): array
    {
        // A file the process may not read comes back from file_get_contents()
        // as false rather than as an exception, and false cast to a string is
        // empty source: zero findings, nothing said. Raising it here makes the
        // caller's catch record it as the gap it is. A missing file already
        // throws on its own, so only readability needs asking about.
        if (! $this->files->isReadable($absolutePath)) {
            throw new RuntimeException("Unable to read [{$absolutePath}].");
        }

        $source = (string) $this->files->get($absolutePath);

        // To PHP's tokeniser a Blade file is almost entirely inline HTML, so
        // `{{ __('x') }}` is never seen. Compiling first turns every directive
        // and echo into real PHP, which also means Laravel's own compiler
        // decides what a directive means instead of us maintaining a list.
        if (str_ends_with($absolutePath, '.blade.php')) {
            $source = $this->blade->compileString($this->blankComments($source));
        }

        return $this->tokenize($source, $absolutePath);
    }

    /**
     * Replaces every Blade comment with the newlines it spans.
     *
     * The compiler deletes `{{-- ... --}}` whole, newlines included, so a
     * multi-line comment pulls every later line of the compiled output upwards
     * and each finding after it would be reported too early. Blanking the
     * comment first leaves the compiler nothing to strip there, so a line in
     * the compiled output is still the line the author wrote. The pattern is
     * the compiler's own, non-greedy and dot-matches-newline, so exactly the
     * same spans are removed; only the newlines survive.
     */
    private function blankComments(string $source): string
    {
        return preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
            $source,
        ) ?? $source;
    }

    /**
     * @return list<Usage>
     */
    private function tokenize(string $php, string $file): array
    {
        $tokens = token_get_all($php, TOKEN_PARSE);
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
