<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * Fuzzy string matching built on the Bitap algorithm.
 *
 * Given a pattern and a text, the matcher finds the best place for the pattern
 * to occur near an expected location, allowing a configurable number of
 * character errors. The result is weighted for both accuracy (how few edits
 * are needed) and proximity (how close the match is to the requested spot).
 */
class Matcher
{
    /** Score at which a match is abandoned (0.0 = exact only, 1.0 = very loose). */
    private float $threshold = 0.5;

    /** How far from `loc` to search before a match scores a full 1.0 worse. */
    private int $distance = 1000;

    /**
     * Pattern length limit imposed by the Bitap bit-vector width.
     *
     * 32 matches every other Diff Match and Patch port. A full 64-bit mask would
     * also overflow into the sign bit (`1 << 63`), so 32 is both compatible and safe.
     */
    private const DEFAULT_MAX_BITS = 32;

    private int $maxBits;

    public function __construct()
    {
        $this->maxBits = self::DEFAULT_MAX_BITS;
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    public function setThreshold(float $threshold): void
    {
        $this->threshold = $threshold;
    }

    public function getDistance(): int
    {
        return $this->distance;
    }

    public function setDistance(int $distance): void
    {
        $this->distance = $distance;
    }

    public function getMaxBits(): int
    {
        return $this->maxBits;
    }

    /**
     * @throws \RangeException When the limit exceeds the integer width.
     */
    public function setMaxBits(int $maxBits): void
    {
        if ($maxBits > PHP_INT_SIZE * 8) {
            throw new \RangeException('Maximum bits cannot exceed the number of bits in an integer.');
        }

        $this->maxBits = $maxBits;
    }

    /**
     * Locate the best instance of $pattern in $text near $loc.
     *
     * @param string|null $text    Text to search.
     * @param string|null $pattern Pattern to look for.
     * @param int         $loc     Location to search around.
     *
     * @return int Offset of the best match, or -1 when nothing reasonable was
     *             found.
     *
     * @throws \InvalidArgumentException When $text or $pattern is null.
     */
    public function main(?string $text, ?string $pattern, int $loc = 0): int
    {
        if ($text === null || $pattern === null) {
            throw new \InvalidArgumentException('Text and pattern must not be null.');
        }

        $loc = max(0, min($loc, Utils::length($text)));

        if ($text === $pattern) {
            return 0;
        }

        if ($text === '') {
            return -1;
        }

        if (Utils::substring($text, $loc, Utils::length($pattern)) === $pattern) {
            return $loc;
        }

        return $this->bitap($text, $pattern, $loc);
    }

    /**
     * Core Bitap search.
     *
     * @throws \RangeException When the pattern is longer than the bit-vector
     *                         can represent.
     */
    public function bitap(string $text, string $pattern, int $loc): int
    {
        $patternLength = Utils::length($pattern);
        if ($this->maxBits !== 0 && $this->maxBits < $patternLength) {
            throw new \RangeException('Pattern is too long for the Bitap algorithm.');
        }

        $alphabet = $this->alphabet($pattern);
        $textLength = Utils::length($text);

        $scoreThreshold = $this->threshold;

        // A quick exact hit lets us narrow the search enormously.
        $bestLoc = Utils::position($text, $pattern, $loc);
        if ($bestLoc !== false) {
            $scoreThreshold = min($this->bitapScore(0, $bestLoc, $patternLength, $loc), $scoreThreshold);
            $bestLoc = Utils::lastPosition($text, $pattern, $loc + $patternLength);
            if ($bestLoc !== false) {
                $scoreThreshold = min($this->bitapScore(0, $bestLoc, $patternLength, $loc), $scoreThreshold);
            }
        }

        $matchMask = 1 << ($patternLength - 1);
        $bestLoc = -1;
        $binMax = $patternLength + $textLength;
        $previous = null;

        for ($errors = 0; $errors < $patternLength; $errors++) {
            // Binary-search how far from `loc` we may stray at this error level.
            $binMin = 0;
            $binMid = $binMax;
            while ($binMin < $binMid) {
                if ($this->bitapScore($errors, $loc + $binMid, $patternLength, $loc) <= $scoreThreshold) {
                    $binMin = $binMid;
                } else {
                    $binMax = $binMid;
                }
                $binMid = intdiv($binMax - $binMin, 2) + $binMin;
            }
            $binMax = $binMid;
            $start = max(1, $loc - $binMid + 1);
            $finish = min($loc + $binMid, $textLength) + $patternLength;

            $row = array_fill(0, $finish + 2, 0);
            $row[$finish + 1] = (1 << $errors) - 1;

            for ($column = $finish; $column > $start - 1; $column--) {
                if ($column - 1 >= $textLength) {
                    $charMatch = 0;
                } else {
                    $charMatch = $alphabet[Utils::charAt($text, $column - 1)] ?? 0;
                }

                if ($errors === 0) {
                    // Exact match: propagate the match mask only.
                    $row[$column] = (($row[$column + 1] << 1) | 1) & $charMatch;
                } else {
                    // Fuzzy match: allow insertions, deletions and substitutions.
                    $row[$column] = ((($row[$column + 1] << 1) | 1) & $charMatch)
                        | ((($previous[$column + 1] | $previous[$column]) << 1) | 1)
                        | $previous[$column + 1];
                }

                if (($row[$column] & $matchMask) !== 0) {
                    $score = $this->bitapScore($errors, $column - 1, $patternLength, $loc);
                    if ($score <= $scoreThreshold) {
                        $scoreThreshold = $score;
                        $bestLoc = $column - 1;
                        if ($bestLoc > $loc) {
                            // Past `loc`: do not drift further than we already have.
                            $start = max(1, 2 * $loc - $bestLoc);
                        } else {
                            // Already before `loc`; nothing better lies further on.
                            break;
                        }
                    }
                }
            }

            if ($this->bitapScore($errors + 1, $loc, $patternLength, $loc) > $scoreThreshold) {
                // More errors cannot possibly help.
                break;
            }

            $previous = $row;
        }

        return $bestLoc;
    }

    /**
     * Score a candidate match: 0.0 is perfect, 1.0 is poor.
     */
    private function bitapScore(int $errors, int $matchLoc, int $patternLength, int $searchLoc): float
    {
        $accuracy = $errors / $patternLength;
        $proximity = abs($searchLoc - $matchLoc);

        if ($this->distance === 0) {
            return $proximity !== 0 ? 1.0 : $accuracy;
        }

        return $accuracy + ($proximity / $this->distance);
    }

    /**
     * Build the Bitap alphabet: a bit-mask of positions per character.
     *
     * @return array<string, int>
     */
    public function alphabet(string $pattern): array
    {
        $alphabet = [];
        $length = Utils::length($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = Utils::charAt($pattern, $i);
            $alphabet[$char] = ($alphabet[$char] ?? 0) | (1 << ($length - $i - 1));
        }

        return $alphabet;
    }
}
