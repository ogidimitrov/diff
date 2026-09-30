<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * Computes the character-based difference between two texts.
 *
 * The engine implements Myers' O(ND) difference algorithm with the usual
 * layer of pre-diff speed-ups (common prefix/suffix trimming, a half-match
 * heuristic, an optional line-level pre-pass) and post-diff clean-ups
 * (merging trivial edits and removing semantically noise equalities).
 *
 * A diff is a list of `[operation, text]` pairs, where the operation is one
 * of the constants below. For example:
 *
 *     [
 *         [Diff::DELETE, 'Hello'],
 *         [Diff::INSERT, 'Goodbye'],
 *         [Diff::EQUAL,  ' world.'],
 *     ]
 *
 * reads as "delete 'Hello', insert 'Goodbye' and keep ' world.'".
 */
class Diff
{
    public const DELETE = -1;
    public const INSERT = 1;
    public const EQUAL = 0;

    /**
     * Texts longer than this (on both sides) are diffed line-by-line first.
     */
    private const LINE_MODE_THRESHOLD = 100;

    private DiffToolkit $toolkit;
    private float $timeout = 1.0;
    private int $editCost = 4;

    public function __construct(?DiffToolkit $toolkit = null)
    {
        $this->toolkit = $toolkit ?? new DiffToolkit();
    }

    public function getToolkit(): DiffToolkit
    {
        return $this->toolkit;
    }

    /**
     * Seconds allowed for a single diff before the algorithm gives up and
     * returns a coarser result. Zero means "no limit".
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Cost of an empty edit operation, used when discarding trivial
     * equalities.
     */
    public function getEditCost(): int
    {
        return $this->editCost;
    }

    public function setEditCost(int $editCost): void
    {
        $this->editCost = $editCost;
    }

    /**
     * Diff two texts.
     *
     * The texts share their common prefix and suffix stripped off before the
     * expensive middle is diffed.
     *
     * @param string|null $text1      Old text.
     * @param string|null $text2      New text.
     * @param bool        $checklines Whether to try a line-level diff first for
     *                                large inputs (faster, occasionally less
     *                                optimal).
     * @param float|null  $deadline   Internal recursion parameter; callers
     *                                should tune {@see setTimeout()} instead.
     *
     * @return list<array{0: int, 1: string}>
     *
     * @throws \InvalidArgumentException When either text is null.
     */
    public function main(?string $text1, ?string $text2, bool $checklines = true, ?float $deadline = null): array
    {
        if ($text1 === null || $text2 === null) {
            throw new \InvalidArgumentException('Texts to diff must not be null.');
        }

        if ($deadline === null) {
            $deadline = $this->timeout > 0 ? microtime(true) + $this->timeout : PHP_FLOAT_MAX;
        }

        if ($text1 === $text2) {
            return $text1 === '' ? [] : [[self::EQUAL, $text1]];
        }

        $commonPrefix = $this->trimCommonPrefix($text1, $text2);
        $commonSuffix = '';
        $commonLength = $this->toolkit->commonSuffix($text1, $text2);
        if ($commonLength > 0) {
            $commonSuffix = Utils::substring($text1, -$commonLength);
            $text1 = Utils::substring($text1, 0, -$commonLength);
            $text2 = Utils::substring($text2, 0, -$commonLength);
        }

        $diffs = $this->compute($text1, $text2, $checklines, $deadline);

        if ($commonPrefix !== '') {
            array_unshift($diffs, [self::EQUAL, $commonPrefix]);
        }
        if ($commonSuffix !== '') {
            $diffs[] = [self::EQUAL, $commonSuffix];
        }

        return $this->cleanupMerge($diffs);
    }

    /**
     * Strip (and return) the common prefix, mutating both texts in place.
     *
     * @param-out string $text1
     * @param-out string $text2
     */
    private function trimCommonPrefix(string &$text1, string &$text2): string
    {
        $commonLength = $this->toolkit->commonPrefix($text1, $text2);
        if ($commonLength === 0) {
            return '';
        }

        $commonPrefix = Utils::substring($text1, 0, $commonLength);
        $text1 = Utils::substring($text1, $commonLength);
        $text2 = Utils::substring($text2, $commonLength);

        return $commonPrefix;
    }

    /**
     * Diff two texts assumed to have no common prefix or suffix.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function compute(string $text1, string $text2, bool $checklines, float $deadline): array
    {
        if ($text1 === '') {
            return [[self::INSERT, $text2]];
        }
        if ($text2 === '') {
            return [[self::DELETE, $text1]];
        }

        $text1Length = Utils::length($text1);
        $text2Length = Utils::length($text2);

        if ($text1Length < $text2Length) {
            $shortText = $text1;
            $longText = $text2;
        } else {
            $shortText = $text2;
            $longText = $text1;
        }

        // Shortcut: the shorter text occurs inside the longer one.
        $index = Utils::position($longText, $shortText);
        if ($index !== false) {
            $diffs = [
                [self::INSERT, Utils::substring($longText, 0, $index)],
                [self::EQUAL, $shortText],
                [self::INSERT, Utils::substring($longText, $index + Utils::length($shortText))],
            ];
            if ($text2Length < $text1Length) {
                // The diff is reversed: the extra text is a deletion.
                $diffs[0][0] = self::DELETE;
                $diffs[2][0] = self::DELETE;
            }

            return $diffs;
        }

        if (Utils::length($shortText) === 1) {
            // A single character can never be equal after the check above.
            return [[self::DELETE, $text1], [self::INSERT, $text2]];
        }

        if ($this->timeout > 0) {
            $halfMatch = $this->toolkit->halfMatch($text1, $text2);
            if ($halfMatch !== null) {
                [$text1A, $text1B, $text2A, $text2B, $middle] = $halfMatch;

                return array_merge(
                    $this->main($text1A, $text2A, $checklines, $deadline),
                    [[self::EQUAL, $middle]],
                    $this->main($text1B, $text2B, $checklines, $deadline),
                );
            }
        }

        if ($checklines && $text1Length > self::LINE_MODE_THRESHOLD && $text2Length > self::LINE_MODE_THRESHOLD) {
            return $this->lineMode($text1, $text2, $deadline);
        }

        return $this->bisect($text1, $text2, $deadline);
    }

    /**
     * Speed-up for large texts: diff whole lines first, then rediff the
     * changed blocks character by character.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function lineMode(string $text1, string $text2, float $deadline): array
    {
        [$chars1, $chars2, $lineArray] = $this->toolkit->linesToChars($text1, $text2);

        $diffs = $this->main($chars1, $chars2, false, $deadline);
        $diffs = $this->toolkit->charsToLines($diffs, $lineArray);

        // Line mode can report coincidental matches (e.g. shared blank lines).
        $diffs = $this->cleanupSemantic($diffs);

        // Rediff every replaced block at the character level.
        $diffs[] = [self::EQUAL, ''];
        $pointer = 0;
        $countDelete = 0;
        $countInsert = 0;
        $textDelete = '';
        $textInsert = '';

        while ($pointer < count($diffs)) {
            switch ($diffs[$pointer][0]) {
                case self::DELETE:
                    $countDelete++;
                    $textDelete .= $diffs[$pointer][1];
                    break;
                case self::INSERT:
                    $countInsert++;
                    $textInsert .= $diffs[$pointer][1];
                    break;
                case self::EQUAL:
                    if ($countDelete > 0 && $countInsert > 0) {
                        $subDiffs = $this->main($textDelete, $textInsert, false, $deadline);
                        array_splice(
                            $diffs,
                            $pointer - $countDelete - $countInsert,
                            $countDelete + $countInsert,
                            $subDiffs,
                        );
                        $pointer = $pointer - $countDelete - $countInsert + count($subDiffs);
                    }
                    $countDelete = 0;
                    $countInsert = 0;
                    $textDelete = '';
                    $textInsert = '';
                    break;
            }
            $pointer++;
        }

        array_pop($diffs);

        return $diffs;
    }

    /**
     * Myers' bidirectional "middle snake" search.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function bisect(string $text1, string $text2, float $deadline): array
    {
        // Work on code-point arrays so indexing and comparison stay O(1) even
        // for multi-byte input.
        $chars1 = Utils::split($text1);
        $chars2 = Utils::split($text2);
        $text1Length = count($chars1);
        $text2Length = count($chars2);

        $maxDistance = intdiv($text1Length + $text2Length + 1, 2);
        $offset = $maxDistance;
        $vectorLength = 2 * $maxDistance;

        $forward = array_fill(0, $vectorLength, -1);
        $forward[$offset + 1] = 0;
        $reverse = $forward;
        $delta = $text1Length - $text2Length;
        $frontOverlaps = $delta % 2 !== 0;

        $k1Start = $k1End = $k2Start = $k2End = 0;

        for ($distance = 0; $distance < $maxDistance; $distance++) {
            if (microtime(true) > $deadline) {
                break;
            }

            // Walk the forward path one step.
            for ($k1 = -$distance + $k1Start; $k1 < $distance + 1 - $k1End; $k1 += 2) {
                $k1Offset = $offset + $k1;
                if ($k1 === -$distance || ($k1 !== $distance && $forward[$k1Offset - 1] < $forward[$k1Offset + 1])) {
                    $x1 = $forward[$k1Offset + 1];
                } else {
                    $x1 = $forward[$k1Offset - 1] + 1;
                }
                $y1 = $x1 - $k1;
                while ($x1 < $text1Length && $y1 < $text2Length && $chars1[$x1] === $chars2[$y1]) {
                    $x1++;
                    $y1++;
                }
                $forward[$k1Offset] = $x1;

                if ($x1 > $text1Length) {
                    $k1End += 2;
                } elseif ($y1 > $text2Length) {
                    $k1Start += 2;
                } elseif ($frontOverlaps) {
                    $k2Offset = $offset + $delta - $k1;
                    if ($k2Offset >= 0 && $k2Offset < $vectorLength && $reverse[$k2Offset] !== -1) {
                        $x2 = $text1Length - $reverse[$k2Offset];
                        if ($x1 >= $x2) {
                            return $this->bisectSplit($text1, $text2, $x1, $y1, $deadline);
                        }
                    }
                }
            }

            // Walk the reverse path one step.
            for ($k2 = -$distance + $k2Start; $k2 < $distance + 1 - $k2End; $k2 += 2) {
                $k2Offset = $offset + $k2;
                if ($k2 === -$distance || ($k2 !== $distance && $reverse[$k2Offset - 1] < $reverse[$k2Offset + 1])) {
                    $x2 = $reverse[$k2Offset + 1];
                } else {
                    $x2 = $reverse[$k2Offset - 1] + 1;
                }
                $y2 = $x2 - $k2;
                while (
                    $x2 < $text1Length && $y2 < $text2Length
                    && $chars1[$text1Length - $x2 - 1] === $chars2[$text2Length - $y2 - 1]
                ) {
                    $x2++;
                    $y2++;
                }
                $reverse[$k2Offset] = $x2;

                if ($x2 > $text1Length) {
                    $k2End += 2;
                } elseif ($y2 > $text2Length) {
                    $k2Start += 2;
                } elseif (!$frontOverlaps) {
                    $k1Offset = $offset + $delta - $k2;
                    if ($k1Offset >= 0 && $k1Offset < $vectorLength && $forward[$k1Offset] !== -1) {
                        $x1 = $forward[$k1Offset];
                        $y1 = $offset + $x1 - $k1Offset;
                        $x2 = $text1Length - $x2;
                        if ($x1 >= $x2) {
                            return $this->bisectSplit($text1, $text2, $x1, $y1, $deadline);
                        }
                    }
                }
            }
        }

        // The deadline was hit, or there is no commonality at all.
        return [[self::DELETE, $text1], [self::INSERT, $text2]];
    }

    /**
     * Split the problem at a detected middle snake and recurse.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function bisectSplit(string $text1, string $text2, int $x, int $y, float $deadline): array
    {
        return array_merge(
            $this->main(Utils::substring($text1, 0, $x), Utils::substring($text2, 0, $y), false, $deadline),
            $this->main(Utils::substring($text1, $x), Utils::substring($text2, $y), false, $deadline),
        );
    }

    /**
     * Reorder and merge like edit sections so the diff contains no adjacent
     * edits of the same kind and no empty equalities.
     *
     * @param list<array{0: int, 1: string}> $diffs
     *
     * @return list<array{0: int, 1: string}>
     */
    public function cleanupMerge(array $diffs): array
    {
        $diffs[] = [self::EQUAL, ''];
        $pointer = 0;
        $countDelete = 0;
        $countInsert = 0;
        $textDelete = '';
        $textInsert = '';

        while ($pointer < count($diffs)) {
            switch ($diffs[$pointer][0]) {
                case self::INSERT:
                    $countInsert++;
                    $textInsert .= $diffs[$pointer][1];
                    $pointer++;
                    break;

                case self::DELETE:
                    $countDelete++;
                    $textDelete .= $diffs[$pointer][1];
                    $pointer++;
                    break;

                case self::EQUAL:
                    // Reaching an equality is the moment to reconcile the edits
                    // gathered since the previous one.
                    if ($countDelete + $countInsert > 1) {
                        if ($countDelete !== 0 && $countInsert !== 0) {
                            // Pull a shared prefix out of the insertion and the
                            // deletion and fold it into the previous equality.
                            $commonLength = $this->toolkit->commonPrefix($textInsert, $textDelete);
                            if ($commonLength !== 0) {
                                $equalIndex = $pointer - $countDelete - $countInsert - 1;
                                if ($equalIndex >= 0 && $diffs[$equalIndex][0] === self::EQUAL) {
                                    $diffs[$equalIndex][1] .= Utils::substring($textInsert, 0, $commonLength);
                                } else {
                                    array_unshift($diffs, [self::EQUAL, Utils::substring($textInsert, 0, $commonLength)]);
                                    $pointer++;
                                }
                                $textInsert = Utils::substring($textInsert, $commonLength);
                                $textDelete = Utils::substring($textDelete, $commonLength);
                            }

                            // Same for a shared suffix.
                            $commonLength = $this->toolkit->commonSuffix($textInsert, $textDelete);
                            if ($commonLength !== 0) {
                                $diffs[$pointer][1] = Utils::substring($textInsert, -$commonLength) . $diffs[$pointer][1];
                                $textInsert = Utils::substring($textInsert, 0, -$commonLength);
                                $textDelete = Utils::substring($textDelete, 0, -$commonLength);
                            }
                        }

                        // Delete the offending records and add the merged ones.
                        // Empty edits are dropped entirely, as Google's original
                        // does: emitting them leaves degenerate `[DELETE, '']`
                        // records that block later merging.
                        $merged = [];
                        if ($textDelete !== '') {
                            $merged[] = [self::DELETE, $textDelete];
                        }
                        if ($textInsert !== '') {
                            $merged[] = [self::INSERT, $textInsert];
                        }
                        $spliceStart = $pointer - $countDelete - $countInsert;
                        array_splice($diffs, $spliceStart, $countDelete + $countInsert, $merged);
                        $pointer = $spliceStart + count($merged) + 1;
                    } elseif ($pointer !== 0 && $diffs[$pointer - 1][0] === self::EQUAL) {
                        // Fold this equality into the previous one.
                        $diffs[$pointer - 1][1] .= $diffs[$pointer][1];
                        array_splice($diffs, $pointer, 1);
                    } else {
                        $pointer++;
                    }

                    $countDelete = 0;
                    $countInsert = 0;
                    $textDelete = '';
                    $textInsert = '';
                    break;
            }
        }

        if ($diffs[count($diffs) - 1][1] === '') {
            array_pop($diffs);
        }

        // Second pass: nudge a lone edit sideways to absorb a neighbouring
        // equality, e.g. A<ins>BA</ins>C -> <ins>AB</ins>AC.
        $changed = false;
        $pointer = 1;
        while ($pointer < count($diffs) - 1) {
            if ($diffs[$pointer - 1][0] === self::EQUAL && $diffs[$pointer + 1][0] === self::EQUAL) {
                $previous = $diffs[$pointer - 1][1];
                $edit = $diffs[$pointer][1];
                $previousLength = Utils::length($previous);

                if ($previous === '' || Utils::substring($edit, -$previousLength) === $previous) {
                    // Shift the edit over the previous equality.
                    if ($previous !== '') {
                        $diffs[$pointer][1] = $previous . Utils::substring($edit, 0, -$previousLength);
                        $diffs[$pointer + 1][1] = $previous . $diffs[$pointer + 1][1];
                    }
                    array_splice($diffs, $pointer - 1, 1);
                    $changed = true;
                } else {
                    $next = $diffs[$pointer + 1][1];
                    $nextLength = Utils::length($next);
                    if ($next === '' || Utils::substring($edit, 0, $nextLength) === $next) {
                        // Shift the edit over the next equality.
                        if ($next !== '') {
                            $diffs[$pointer - 1][1] = $previous . $next;
                            $diffs[$pointer][1] = Utils::substring($edit, $nextLength) . $next;
                        }
                        array_splice($diffs, $pointer + 1, 1);
                        $changed = true;
                    }
                }
            }
            $pointer++;
        }

        return $changed ? $this->cleanupMerge($diffs) : $diffs;
    }

    /**
     * Shift edits sideways to align them with word or line boundaries where
     * possible.
     *
     * @param list<array{0: int, 1: string}> $diffs
     *
     * @return list<array{0: int, 1: string}>
     */
    public function cleanupSemanticLossless(array $diffs): array
    {
        $pointer = 1;
        while ($pointer < count($diffs) - 1) {
            if ($diffs[$pointer - 1][0] === self::EQUAL && $diffs[$pointer + 1][0] === self::EQUAL) {
                $equality1 = $diffs[$pointer - 1][1];
                $edit = $diffs[$pointer][1];
                $equality2 = $diffs[$pointer + 1][1];

                // First slide the edit as far left as its content allows.
                $commonOffset = $this->toolkit->commonSuffix($equality1, $edit);
                if ($commonOffset !== 0) {
                    $common = Utils::substring($edit, -$commonOffset);
                    $equality1 = Utils::substring($equality1, 0, -$commonOffset);
                    $edit = $common . Utils::substring($edit, 0, -$commonOffset);
                    $equality2 = $common . $equality2;
                }

                // Then step right one character at a time, keeping the
                // alignment that scores best on word/line boundaries.
                $bestEquality1 = $equality1;
                $bestEdit = $edit;
                $bestEquality2 = $equality2;
                $bestScore = $this->semanticScore($equality1, $edit) + $this->semanticScore($edit, $equality2);

                while (
                    $edit !== '' && $equality2 !== ''
                    && Utils::substring($edit, 0, 1) === Utils::substring($equality2, 0, 1)
                ) {
                    $equality1 .= Utils::substring($edit, 0, 1);
                    $edit = Utils::substring($edit, 1) . Utils::substring($equality2, 0, 1);
                    $equality2 = Utils::substring($equality2, 1);
                    $score = $this->semanticScore($equality1, $edit) + $this->semanticScore($edit, $equality2);
                    if ($score >= $bestScore) {
                        $bestScore = $score;
                        $bestEquality1 = $equality1;
                        $bestEdit = $edit;
                        $bestEquality2 = $equality2;
                    }
                }

                if ($diffs[$pointer - 1][1] !== $bestEquality1) {
                    if ($bestEquality1 !== '') {
                        $diffs[$pointer - 1][1] = $bestEquality1;
                    } else {
                        array_splice($diffs, $pointer - 1, 1);
                        $pointer--;
                    }

                    $diffs[$pointer][1] = $bestEdit;

                    if ($bestEquality2 !== '') {
                        $diffs[$pointer + 1][1] = $bestEquality2;
                    } else {
                        array_splice($diffs, $pointer + 1, 1);
                        $pointer--;
                    }
                }
            }
            $pointer++;
        }

        return $diffs;
    }

    /**
     * Score how natural the boundary between $one and $two is, from 6 (a blank
     * line) down to 0 (mid-word). Higher is a nicer place to break an edit.
     */
    private function semanticScore(string $one, string $two): int
    {
        if ($one === '' || $two === '') {
            return 6;
        }

        $char1 = Utils::substring($one, -1, 1);
        $char2 = Utils::substring($two, 0, 1);

        $nonAlphanumeric1 = preg_match('/[^\p{L}\p{N}]/u', $char1) === 1;
        $nonAlphanumeric2 = preg_match('/[^\p{L}\p{N}]/u', $char2) === 1;
        $whitespace1 = $nonAlphanumeric1 && preg_match('/\s/u', $char1) === 1;
        $whitespace2 = $nonAlphanumeric2 && preg_match('/\s/u', $char2) === 1;
        $lineBreak1 = $whitespace1 && preg_match('/[\r\n]/u', $char1) === 1;
        $lineBreak2 = $whitespace2 && preg_match('/[\r\n]/u', $char2) === 1;
        $blankLine1 = $lineBreak1 && preg_match('/\n\r?\n$/u', $one) === 1;
        $blankLine2 = $lineBreak2 && preg_match('/^\r?\n\r?\n/u', $two) === 1;

        if ($blankLine1 || $blankLine2) {
            return 5;
        }
        if ($lineBreak1 || $lineBreak2) {
            return 4;
        }
        if ($nonAlphanumeric1 && !$whitespace1 && $whitespace2) {
            return 3;
        }
        if ($whitespace1 || $whitespace2) {
            return 2;
        }
        if ($nonAlphanumeric1 || $nonAlphanumeric2) {
            return 1;
        }

        return 0;
    }

    /**
     * Simplify the diff by dropping equalities that carry little meaning, and
     * by re-factoring overlaps between deletions and insertions.
     *
     * @param list<array{0: int, 1: string}> $diffs
     *
     * @return list<array{0: int, 1: string}>
     */
    public function cleanupSemantic(array $diffs): array
    {
        $changed = false;
        $equalities = [];
        $lastEquality = null;
        $pointer = 0;
        $insertions1 = $deletions1 = 0;
        $insertions2 = $deletions2 = 0;

        while ($pointer < count($diffs)) {
            if ($diffs[$pointer][0] === self::EQUAL) {
                $equalities[] = $pointer;
                $insertions1 = $insertions2;
                $insertions2 = 0;
                $deletions1 = $deletions2;
                $deletions2 = 0;
                $lastEquality = $diffs[$pointer][1];
            } else {
                if ($diffs[$pointer][0] === self::INSERT) {
                    $insertions2 += Utils::length($diffs[$pointer][1]);
                } else {
                    $deletions2 += Utils::length($diffs[$pointer][1]);
                }

                // An equality no longer than the edits on either side is not
                // worth keeping.
                if (
                    $lastEquality !== null
                    && Utils::length($lastEquality) <= max($insertions1, $deletions1)
                    && Utils::length($lastEquality) <= max($insertions2, $deletions2)
                ) {
                    $equalityIndex = (int) array_pop($equalities);
                    // Duplicate the equality as a deletion ...
                    array_splice($diffs, $equalityIndex, 0, [[self::DELETE, $lastEquality]]);
                    // ... and turn the original into an insertion.
                    $diffs[$equalityIndex + 1][0] = self::INSERT;

                    if ($equalities !== []) {
                        array_pop($equalities);
                    }
                    $pointer = $equalities === [] ? -1 : (int) end($equalities);

                    $insertions1 = $deletions1 = $insertions2 = $deletions2 = 0;
                    $lastEquality = null;
                    $changed = true;
                }
            }
            $pointer++;
        }

        if ($changed) {
            $diffs = $this->cleanupMerge($diffs);
        }
        $diffs = $this->cleanupSemanticLossless($diffs);

        // Extract overlaps that sit between a deletion and an insertion, e.g.
        // <del>abcxxx</del><ins>xxxdef</ins> -> <del>abc</del>xxx<ins>def</ins>.
        $pointer = 1;
        while ($pointer < count($diffs)) {
            if ($diffs[$pointer - 1][0] === self::DELETE && $diffs[$pointer][0] === self::INSERT) {
                $deletion = $diffs[$pointer - 1][1];
                $insertion = $diffs[$pointer][1];
                $deletionLength = Utils::length($deletion);
                $insertionLength = Utils::length($insertion);

                $overlap1 = $this->toolkit->commonOverlap($deletion, $insertion);
                $overlap2 = $this->toolkit->commonOverlap($insertion, $deletion);

                if ($overlap1 >= $overlap2) {
                    if ($overlap1 >= $deletionLength / 2 || $overlap1 >= $insertionLength / 2) {
                        // Overlap found. Insert an equality and trim the surrounding edits.
                        // Note the length is computed explicitly: `-0` would wipe the
                        // whole deletion when the overlap is zero (e.g. an empty insertion).
                        array_splice($diffs, $pointer, 0, [[self::EQUAL, Utils::substring($insertion, 0, $overlap1)]]);
                        $diffs[$pointer - 1][1] = Utils::substring($deletion, 0, $deletionLength - $overlap1);
                        $diffs[$pointer + 1][1] = Utils::substring($insertion, $overlap1);
                        $pointer++;
                    }
                } elseif ($overlap2 >= $deletionLength / 2 || $overlap2 >= $insertionLength / 2) {
                    // Reverse overlap: the shared run is a suffix of the insertion and a
                    // prefix of the deletion. Factor it out as an equality, turning
                    // `<del>A</del><ins>B</ins>` into `<ins>B_head</ins>O<del>A_tail</del>`.
                    array_splice($diffs, $pointer, 0, [[self::EQUAL, Utils::substring($deletion, 0, $overlap2)]]);
                    $diffs[$pointer - 1] = [self::INSERT, Utils::substring($insertion, 0, $insertionLength - $overlap2)];
                    $diffs[$pointer + 1] = [self::DELETE, Utils::substring($deletion, $overlap2)];
                    $pointer++;
                }
                $pointer++;
            }
            $pointer++;
        }

        return $diffs;
    }

    /**
     * Drop equalities that only exist to separate two short edits, when doing
     * so keeps the diff cheap to encode.
     *
     * @param list<array{0: int, 1: string}> $diffs
     *
     * @return list<array{0: int, 1: string}>
     */
    public function cleanupEfficiency(array $diffs): array
    {
        $changed = false;
        $equalities = [];
        $lastEquality = null;
        $pointer = 0;
        $preInsert = $preDelete = false;
        $postInsert = $postDelete = false;

        while ($pointer < count($diffs)) {
            if ($diffs[$pointer][0] === self::EQUAL) {
                if (Utils::length($diffs[$pointer][1]) < $this->editCost && ($postInsert || $postDelete)) {
                    $equalities[] = $pointer;
                    $preInsert = $postInsert;
                    $preDelete = $postDelete;
                    $lastEquality = $diffs[$pointer][1];
                } else {
                    $equalities = [];
                    $lastEquality = null;
                }
                $postInsert = false;
                $postDelete = false;
            } else {
                if ($diffs[$pointer][0] === self::DELETE) {
                    $postDelete = true;
                } else {
                    $postInsert = true;
                }

                $surrounding = ($preInsert ? 1 : 0) + ($preDelete ? 1 : 0) + ($postInsert ? 1 : 0) + ($postDelete ? 1 : 0);
                if (
                    $lastEquality !== null
                    && (
                        ($preInsert && $preDelete && $postInsert && $postDelete)
                        || (Utils::length($lastEquality) < $this->editCost / 2 && $surrounding === 3)
                    )
                ) {
                    $equalityIndex = (int) array_pop($equalities);
                    array_splice($diffs, $equalityIndex, 0, [[self::DELETE, $lastEquality]]);
                    $diffs[$equalityIndex + 1][0] = self::INSERT;
                    if ($equalities !== []) {
                        array_pop($equalities);
                    }
                    $lastEquality = null;

                    if ($preInsert && $preDelete) {
                        // Nothing before this point changed; keep scanning.
                        $postInsert = true;
                        $postDelete = true;
                        $equalities = [];
                    } else {
                        if ($equalities !== []) {
                            array_pop($equalities);
                        }
                        $pointer = $equalities === [] ? -1 : (int) end($equalities);
                        $postInsert = false;
                        $postDelete = false;
                    }
                    $changed = true;
                }
            }
            $pointer++;
        }

        return $changed ? $this->cleanupMerge($diffs) : $diffs;
    }

    /**
     * Concatenate the equalities and deletions of a diff.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function text1(array $diffs): string
    {
        $text = '';
        foreach ($diffs as [$operation, $data]) {
            if ($operation !== self::INSERT) {
                $text .= $data;
            }
        }

        return $text;
    }

    /**
     * Concatenate the equalities and insertions of a diff.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function text2(array $diffs): string
    {
        $text = '';
        foreach ($diffs as [$operation, $data]) {
            if ($operation !== self::DELETE) {
                $text .= $data;
            }
        }

        return $text;
    }

    /**
     * Render a diff as an HTML report, wrapping insertions in <ins> and
     * deletions in <del>.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function prettyHtml(array $diffs): string
    {
        $html = '';
        foreach ($diffs as [$operation, $data]) {
            $text = str_replace(
                ['&', '<', '>', "\n"],
                ['&amp;', '&lt;', '&gt;', '&para;<br>'],
                $data,
            );

            $html .= match ($operation) {
                self::INSERT => '<ins style="background:#e6ffe6;">' . $text . '</ins>',
                self::DELETE => '<del style="background:#ffe6e6;">' . $text . '</del>',
                default => '<span>' . $text . '</span>',
            };
        }

        return $html;
    }

    /**
     * Encode a diff as a compact, tab-separated delta string, e.g.
     * `=3\t-2\t+ing`.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function toDelta(array $diffs): string
    {
        $parts = [];
        foreach ($diffs as [$operation, $data]) {
            $parts[] = match ($operation) {
                self::INSERT => '+' . Utils::escape($data),
                self::DELETE => '-' . Utils::length($data),
                default => '=' . Utils::length($data),
            };
        }

        return implode("\t", $parts);
    }

    /**
     * Rebuild a diff from the source text and a delta string produced by
     * {@see toDelta()}.
     *
     * @return list<array{0: int, 1: string}>
     *
     * @throws \InvalidArgumentException When the delta is malformed or does not
     *                                   consume the whole source text.
     */
    public function fromDelta(string $text1, string $delta): array
    {
        $diffs = [];
        $pointer = 0;

        foreach (explode("\t", $delta) as $token) {
            if ($token === '') {
                // Empty tokens are fine (a trailing tab, for instance).
                continue;
            }

            $operation = Utils::substring($token, 0, 1);
            $parameter = Utils::substring($token, 1);

            switch ($operation) {
                case '+':
                    $diffs[] = [self::INSERT, Utils::unescape($parameter)];
                    break;

                case '-':
                case '=':
                    if ($parameter === '' || !ctype_digit($parameter)) {
                        throw new \InvalidArgumentException('Invalid length in delta: ' . $parameter);
                    }
                    $length = (int) $parameter;
                    $diffs[] = [
                        $operation === '=' ? self::EQUAL : self::DELETE,
                        Utils::substring($text1, $pointer, $length),
                    ];
                    $pointer += $length;
                    break;

                default:
                    throw new \InvalidArgumentException('Invalid diff operation in delta: ' . $operation);
            }
        }

        if ($pointer !== Utils::length($text1)) {
            throw new \InvalidArgumentException(
                'Delta length (' . $pointer . ') does not equal source text length (' . Utils::length($text1) . ').',
            );
        }

        return $diffs;
    }

    /**
     * Translate an offset in text1 to the matching offset in text2.
     *
     * For example, in "The cat" -> "The big cat", 1 maps to 1 and 5 maps to 8.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function xIndex(array $diffs, int $loc): int
    {
        $chars1 = 0;
        $chars2 = 0;
        $lastChars1 = 0;
        $lastChars2 = 0;
        $pointer = 0;

        foreach ($diffs as [$operation, $text]) {
            if ($operation !== self::INSERT) {
                $chars1 += Utils::length($text);
            }
            if ($operation !== self::DELETE) {
                $chars2 += Utils::length($text);
            }

            if ($chars1 > $loc) {
                break;
            }

            $lastChars1 = $chars1;
            $lastChars2 = $chars2;
            $pointer++;
        }

        // The location fell inside a deletion.
        if ($pointer !== count($diffs) && $diffs[$pointer][0] === self::DELETE) {
            return $lastChars2;
        }

        return $loc + $lastChars2 - $lastChars1;
    }

    /**
     * Levenshtein distance of a diff: the number of inserted, deleted or
     * substituted characters needed to turn text1 into text2.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function levenshtein(array $diffs): int
    {
        $distance = 0;
        $insertions = 0;
        $deletions = 0;

        foreach ($diffs as [$operation, $text]) {
            switch ($operation) {
                case self::INSERT:
                    $insertions += Utils::length($text);
                    break;
                case self::DELETE:
                    $deletions += Utils::length($text);
                    break;
                default:
                    // A deletion and an insertion this far amount to substitutions.
                    $distance += max($insertions, $deletions);
                    $insertions = 0;
                    $deletions = 0;
                    break;
            }
        }

        return $distance + max($insertions, $deletions);
    }
}
