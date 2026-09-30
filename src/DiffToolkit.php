<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * Text-level building blocks shared by the diff and patch engines.
 *
 * Unlike {@see Utils}, the methods here understand diff concepts: how much of
 * two texts is shared at the start or end, whether the texts share a large
 * common substring, and how to collapse lines into single characters so the
 * Myers algorithm can work on lines instead of characters.
 *
 * @internal
 */
class DiffToolkit
{
    /**
     * Number of code points common to the start of both strings.
     */
    public function commonPrefix(string $text1, string $text2): int
    {
        $byteLength1 = strlen($text1);
        $limit = min($byteLength1, strlen($text2));

        $matched = 0;
        while ($matched < $limit && $text1[$matched] === $text2[$matched]) {
            $matched++;
        }

        // Back up so we never split a multi-byte character in half.
        while ($matched > 0 && $matched < $byteLength1 && (ord($text1[$matched]) & 0xC0) === 0x80) {
            $matched--;
        }

        return $matched === 0 ? 0 : Utils::length(substr($text1, 0, $matched));
    }

    /**
     * Number of code points common to the end of both strings.
     */
    public function commonSuffix(string $text1, string $text2): int
    {
        $byteLength1 = strlen($text1);
        $byteLength2 = strlen($text2);
        $limit = min($byteLength1, $byteLength2);

        $matched = 0;
        while (
            $matched < $limit
            && $text1[$byteLength1 - $matched - 1] === $text2[$byteLength2 - $matched - 1]
        ) {
            $matched++;
        }

        // Advance so we never start in the middle of a multi-byte character.
        while ($matched > 0 && (ord($text1[$byteLength1 - $matched]) & 0xC0) === 0x80) {
            $matched--;
        }

        return $matched === 0 ? 0 : Utils::length(substr($text1, $byteLength1 - $matched));
    }

    /**
     * Length of the longest string that is a suffix of $text1 and a prefix of
     * $text2 (or vice versa).
     */
    public function commonOverlap(string $text1, string $text2): int
    {
        $length1 = Utils::length($text1);
        $length2 = Utils::length($text2);

        if ($length1 === 0 || $length2 === 0) {
            return 0;
        }

        // Trim the longer string down to the length of the shorter one.
        if ($length1 > $length2) {
            $text1 = Utils::substring($text1, -$length2);
        } elseif ($length1 < $length2) {
            $text2 = Utils::substring($text2, 0, $length1);
        }

        $max = min($length1, $length2);
        if ($text1 === $text2) {
            return $max;
        }

        $best = 0;
        $length = 1;
        while (true) {
            $pattern = Utils::substring($text1, -$length);
            $found = Utils::position($text2, $pattern);
            if ($found === false) {
                break;
            }

            $length += $found;
            if ($found === 0 || Utils::substring($text1, -$length) === Utils::substring($text2, 0, $length)) {
                $best = $length;
                $length++;
            }
        }

        return $best;
    }

    /**
     * Look for a shared substring at least half as long as the longer text.
     *
     * Finding one lets the diff be split into two smaller problems. It is a
     * heuristic speed-up, so it may yield a non-minimal diff.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     *         The prefix of text1, the suffix of text1, the prefix of text2,
     *         the suffix of text2 and the shared middle — or null when there is
     *         no useful overlap.
     */
    public function halfMatch(string $text1, string $text2): ?array
    {
        $length1 = Utils::length($text1);
        $length2 = Utils::length($text2);

        if ($length1 > $length2) {
            $longText = $text1;
            $shortText = $text2;
        } else {
            $longText = $text2;
            $shortText = $text1;
        }

        $longLength = Utils::length($longText);
        if ($longLength < 4 || Utils::length($shortText) * 2 < $longLength) {
            // Too short, or too unbalanced, to be worth it.
            return null;
        }

        // The match may start around a quarter or around half-way through.
        $first = $this->halfMatchAt($longText, $shortText, intdiv($longLength + 3, 4));
        $second = $this->halfMatchAt($longText, $shortText, intdiv($longLength + 1, 2));

        if ($first === null && $second === null) {
            return null;
        }

        if ($second === null) {
            $match = $first;
        } elseif ($first === null) {
            $match = $second;
        } else {
            $match = Utils::length($first[4]) > Utils::length($second[4]) ? $first : $second;
        }

        [$longA, $longB, $shortA, $shortB, $middle] = $match;

        // Present the pieces in the order of the original arguments.
        return $length1 > $length2
            ? [$longA, $longB, $shortA, $shortB, $middle]
            : [$shortA, $shortB, $longA, $longB, $middle];
    }

    /**
     * Does a substring of $shortText starting at $index hold at least half of
     * $longText?
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private function halfMatchAt(string $longText, string $shortText, int $index): ?array
    {
        $seed = Utils::substring($longText, $index, intdiv(Utils::length($longText), 4));

        $bestCommon = '';
        $bestLongA = $bestLongB = $bestShortA = $bestShortB = '';

        $position = Utils::position($shortText, $seed);
        while ($position !== false) {
            $prefixLength = $this->commonPrefix(
                Utils::substring($longText, $index),
                Utils::substring($shortText, $position),
            );
            $suffixLength = $this->commonSuffix(
                Utils::substring($longText, 0, $index),
                Utils::substring($shortText, 0, $position),
            );

            if (Utils::length($bestCommon) < $suffixLength + $prefixLength) {
                $bestCommon = Utils::substring($shortText, $position - $suffixLength, $suffixLength)
                    . Utils::substring($shortText, $position, $prefixLength);
                $bestLongA = Utils::substring($longText, 0, $index - $suffixLength);
                $bestLongB = Utils::substring($longText, $index + $prefixLength);
                $bestShortA = Utils::substring($shortText, 0, $position - $suffixLength);
                $bestShortB = Utils::substring($shortText, $position + $prefixLength);
            }

            $position = Utils::position($shortText, $seed, $position + 1);
        }

        if (Utils::length($bestCommon) * 2 >= Utils::length($longText)) {
            return [$bestLongA, $bestLongB, $bestShortA, $bestShortB, $bestCommon];
        }

        return null;
    }

    /**
     * Collapse both texts to a string where a single Unicode character stands
     * for a whole line.
     *
     * @return array{0: string, 1: string, 2: list<string>} The encoded text1,
     *         the encoded text2 and the table of unique lines. Element 0 of the
     *         table is a deliberate placeholder so code point 0 is never used.
     */
    public function linesToChars(string $text1, string $text2): array
    {
        $lineArray = [''];
        $lineHash = [];

        $chars1 = $this->encodeLines($text1, $lineArray, $lineHash);
        $chars2 = $this->encodeLines($text2, $lineArray, $lineHash);

        return [$chars1, $chars2, $lineArray];
    }

    /**
     * @param list<string>          $lineArray
     * @param array<string, int>    $lineHash
     */
    private function encodeLines(string $text, array &$lineArray, array &$lineHash): string
    {
        $chars = '';

        $lines = explode("\n", $text);
        $endsWithNewline = end($lines) === '';
        if ($endsWithNewline) {
            array_pop($lines);
        }
        $lineCount = count($lines);

        foreach ($lines as $index => $line) {
            if ($index + 1 < $lineCount || $endsWithNewline) {
                $line .= "\n";
            }

            if (!isset($lineHash[$line])) {
                $lineArray[] = $line;
                $lineHash[$line] = count($lineArray) - 1;
                if ($lineHash[$line] > 0x10FFFF) {
                    throw new \RuntimeException('Text has too many unique lines for line-mode diffing.');
                }
            }

            $chars .= Utils::charFromCode($lineHash[$line]);
        }

        return $chars;
    }

    /**
     * Expand a diff over line hashes back into real text.
     *
     * @param list<array{0: int, 1: string}> $diffs
     * @param list<string>                   $lineArray
     *
     * @return list<array{0: int, 1: string}>
     */
    public function charsToLines(array $diffs, array $lineArray): array
    {
        foreach ($diffs as &$diff) {
            $text = '';
            foreach (Utils::split($diff[1]) as $char) {
                $text .= $lineArray[Utils::codeFromChar($char)];
            }
            $diff[1] = $text;
        }
        unset($diff);

        return $diffs;
    }
}
