<?php

declare(strict_types=1);

namespace Pest\Browser\Support;

/**
 * @internal
 */
final class Str
{
    /**
     * Collapses a string onto one line and truncates it, for use in a message.
     *
     * Anything a failure message quotes back from the page arrives with the
     * markup's own indentation in it, which turns a one-word label into six
     * lines of whitespace. Collapsing first means the length that follows
     * counts characters a reader will actually see.
     */
    public static function snippet(string $target, int $length): string
    {
        $target = mb_trim((string) preg_replace('/\s+/u', ' ', $target));

        return mb_strlen($target) > $length
            ? mb_substr($target, 0, $length).'...'
            : $target;
    }

    /**
     * Check if the given string is a regex.
     */
    public static function isRegex(string $target): bool
    {
        if (mb_strlen($target) < 2) {
            return false;
        }

        if (($delimiter = mb_substr($target, 0, 1)) !== mb_substr($target, -1, 1)) {
            return false;
        }

        return preg_match('/[^a-zA-Z0-9]/', $delimiter) !== false;
    }
}
