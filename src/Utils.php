<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * Code-point aware string helpers used throughout the package.
 *
 * Every string handled by this library is expected to be UTF-8. PHP's built-in
 * string functions count bytes, so a naive implementation would happily slice
 * a multi-byte character such as "é" or "🙂" in half and produce a corrupt
 * diff. The helpers below always measure offsets and lengths in code points
 * (whole characters) instead, and pin the encoding to UTF-8 so results never
 * depend on the process-wide `mb_internal_encoding()` setting.
 *
 * @internal
 */
class Utils
{
    private function __construct()
    {
    }

    /**
     * Number of code points in $text.
     */
    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Extract a slice of $text using code-point offsets.
     *
     * Behaves like mb_substr(), including support for negative offsets
     * (counting back from the end) and negative lengths (leaving characters
     * off the end).
     */
    public static function substring(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    /**
     * The code point at $index, or an empty string when out of range.
     */
    public static function charAt(string $text, int $index): string
    {
        return mb_substr($text, $index, 1, 'UTF-8');
    }

    /**
     * Code-point offset of the first occurrence of $needle in $text.
     *
     * @return int|false
     */
    public static function position(string $text, string $needle, int $offset = 0): int|false
    {
        return mb_strpos($text, $needle, $offset, 'UTF-8');
    }

    /**
     * Code-point offset of the last occurrence of $needle in $text.
     *
     * @return int|false
     */
    public static function lastPosition(string $text, string $needle, ?int $offset = null): int|false
    {
        return $offset === null
            ? mb_strrpos($text, $needle, 0, 'UTF-8')
            : mb_strrpos($text, $needle, $offset, 'UTF-8');
    }

    /**
     * Split $text into a list of single code-point strings.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return mb_str_split($text, 1, 'UTF-8');
    }

    /**
     * Build the UTF-8 character for a Unicode code point.
     */
    public static function charFromCode(int $code): string
    {
        return mb_chr($code, 'UTF-8');
    }

    /**
     * The Unicode code point of a single character.
     */
    public static function codeFromChar(string $char): int
    {
        return mb_ord($char, 'UTF-8');
    }

    /**
     * Percent-encode a string for the delta / patch text format.
     *
     * Equivalent to Python's `urllib.parse.quote($string, "!~*'();/?:@&=+$,# ")`:
     * letters, digits and the punctuation listed below are left untouched,
     * everything else is written as `%xx`.
     */
    public static function escape(string $string): string
    {
        return strtr(rawurlencode($string), [
            '%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(',
            '%29' => ')', '%3B' => ';', '%2F' => '/', '%3F' => '?',
            '%3A' => ':', '%40' => '@', '%26' => '&', '%3D' => '=',
            '%2B' => '+', '%24' => '$', '%2C' => ',', '%23' => '#',
            '%20' => ' ',
        ]);
    }

    /**
     * Reverse the escaping performed by {@see escape()}.
     */
    public static function unescape(string $string): string
    {
        return rawurldecode($string);
    }
}
