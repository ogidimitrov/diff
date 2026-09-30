<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * Creates patches from diffs and applies them to text.
 *
 * Patching is deliberately forgiving: when a patch does not land exactly
 * where it was expected the matcher looks nearby, still applies it where it
 * can, and reports which patches succeeded. This makes patches resilient to
 * the small edits a document tends to accumulate between being diffed and
 * being patched.
 */
class Patch
{
    /** Similarity required before a large deletion is accepted. */
    private float $deleteThreshold = 0.5;

    /** How much surrounding context each patch carries. */
    private int $margin = 4;

    public function __construct(
        private readonly Diff $diff,
        private readonly Matcher $matcher,
    ) {
    }

    public function getDeleteThreshold(): float
    {
        return $this->deleteThreshold;
    }

    public function setDeleteThreshold(float $deleteThreshold): void
    {
        $this->deleteThreshold = $deleteThreshold;
    }

    public function getMargin(): int
    {
        return $this->margin;
    }

    /**
     * @throws \InvalidArgumentException When the margin is less than one, which
     *                                   would stop the context window from ever
     *                                   growing (an infinite loop on repetitive
     *                                   input).
     */
    public function setMargin(int $margin): void
    {
        if ($margin < 1) {
            throw new \InvalidArgumentException('Patch margin must be at least 1.');
        }

        $this->margin = $margin;
    }

    /**
     * Compute the list of patches that turn one text into another.
     *
     * Four call styles are supported:
     *   1. text1 and text2;
     *   2. a diff (text1 is derived from it);
     *   3. text1 and a diff (the optimal form);
     *   4. text1, text2 and a diff (deprecated).
     *
     * @param string|list<array{0: int, 1: string}>      $a
     * @param string|list<array{0: int, 1: string}>|null $b
     * @param list<array{0: int, 1: string}>|null        $c
     *
     * @return list<PatchObject>
     *
     * @throws \InvalidArgumentException When the arguments match no known call
     *                                   style.
     */
    public function make(string|array $a, string|array|null $b = null, ?array $c = null): array
    {
        if (is_string($a) && is_string($b) && $c === null) {
            // Style 1: two texts.
            $text1 = $a;
            $diffs = $this->diff->main($text1, $b);
            if (count($diffs) > 2) {
                $diffs = $this->diff->cleanupSemantic($diffs);
                $diffs = $this->diff->cleanupEfficiency($diffs);
            }
        } elseif (is_array($a) && $b === null) {
            // Style 2: a diff.
            $diffs = $a;
            $text1 = $this->diff->text1($diffs);
        } elseif (is_string($a) && is_array($b) && $c === null) {
            // Style 3: text1 and a diff.
            $text1 = $a;
            $diffs = $b;
        } elseif (is_string($a) && is_string($b) && $c !== null) {
            // Style 4: text1, text2 and a diff.
            $text1 = $a;
            $diffs = $c;
        } else {
            throw new \InvalidArgumentException('Unknown call format for patch_make().');
        }

        $patches = [];
        $patch = new PatchObject();

        // Offsets into the source and destination text as we walk the diff.
        $chars1 = 0;
        $chars2 = 0;
        // The text each patch is written against, and the text it produces.
        $prePatchText = $text1;
        $postPatchText = $text1;

        $diffCount = count($diffs);
        for ($i = 0; $i < $diffCount; $i++) {
            [$operation, $text] = $diffs[$i];
            $textLength = Utils::length($text);

            if ($patch->getChanges() === [] && $operation !== Diff::EQUAL) {
                // A fresh patch begins on the first non-equal edit.
                $patch->setStart1($chars1);
                $patch->setStart2($chars2);
            }

            if ($operation === Diff::INSERT) {
                $patch->appendChanges($diffs[$i]);
                $patch->setLength2($patch->getLength2() + $textLength);
                $postPatchText = Utils::substring($postPatchText, 0, $chars2)
                    . $text
                    . Utils::substring($postPatchText, $chars2);
            } elseif ($operation === Diff::DELETE) {
                $patch->appendChanges($diffs[$i]);
                $patch->setLength1($patch->getLength1() + $textLength);
                $postPatchText = Utils::substring($postPatchText, 0, $chars2)
                    . Utils::substring($postPatchText, $chars2 + $textLength);
            } elseif (
                $textLength <= 2 * $this->margin
                && $patch->getChanges() !== []
                && $i !== $diffCount - 1
            ) {
                // A short equality stays inside the current patch.
                $patch->appendChanges($diffs[$i]);
                $patch->setLength1($patch->getLength1() + $textLength);
                $patch->setLength2($patch->getLength2() + $textLength);
            }

            if ($operation === Diff::EQUAL && $textLength >= 2 * $this->margin && $patch->getChanges() !== []) {
                $this->addContext($patch, $prePatchText);
                $patches[] = $patch;
                $patch = new PatchObject();

                // Patch coordinates are relative to the text the previous patch
                // produced, so continue from there.
                $prePatchText = $postPatchText;
                $chars1 = $chars2;
            }

            if ($operation !== Diff::INSERT) {
                $chars1 += $textLength;
            }
            if ($operation !== Diff::DELETE) {
                $chars2 += $textLength;
            }
        }

        if ($patch->getChanges() !== []) {
            $this->addContext($patch, $prePatchText);
            $patches[] = $patch;
        }

        return $patches;
    }

    /**
     * Grow a patch's context until the target text is unique, without letting
     * the search pattern exceed the matcher's bit limit.
     */
    public function addContext(PatchObject $patch, string $text): void
    {
        if (Utils::length($text) === 0) {
            return;
        }

        $padding = 0;
        $pattern = Utils::substring($text, $patch->getStart1(), $patch->getLength1());
        $maxBits = $this->matcher->getMaxBits();

        while (
            ($pattern === '' || Utils::position($text, $pattern) !== Utils::lastPosition($text, $pattern))
            && ($maxBits === 0 || Utils::length($pattern) < $maxBits - 2 * $this->margin)
        ) {
            $padding += $this->margin;
            $from = max(0, $patch->getStart2() - $padding);
            $pattern = Utils::substring(
                $text,
                $from,
                $patch->getStart2() + $patch->getLength1() + $padding - $from,
            );
        }

        // One extra chunk of context for good measure.
        $padding += $this->margin;

        $prefix = Utils::substring($text, max(0, $patch->getStart2() - $padding), min($patch->getStart2(), $padding));
        if ($prefix !== '') {
            $patch->prependChanges([Diff::EQUAL, $prefix]);
        }

        $suffix = Utils::substring($text, $patch->getStart2() + $patch->getLength1(), $padding);
        if ($suffix !== '') {
            $patch->appendChanges([Diff::EQUAL, $suffix]);
        }

        $prefixLength = Utils::length($prefix);
        $patch->setStart1($patch->getStart1() - $prefixLength);
        $patch->setStart2($patch->getStart2() - $prefixLength);

        $suffixLength = Utils::length($suffix);
        $patch->setLength1($patch->getLength1() + $prefixLength + $suffixLength);
        $patch->setLength2($patch->getLength2() + $prefixLength + $suffixLength);
    }

    /**
     * Serialise patches to their text form.
     *
     * @param list<PatchObject> $patches
     */
    public function toText(array $patches): string
    {
        $text = '';
        foreach ($patches as $patch) {
            $text .= $patch->toText();
        }

        return $text;
    }

    /**
     * Parse the text form produced by {@see toText()} back into patches.
     *
     * @return list<PatchObject>
     *
     * @throws \InvalidArgumentException When a patch header is malformed.
     * @throws \UnexpectedValueException When a body line has an unknown prefix.
     */
    public function fromText(string $patchText): array
    {
        $patches = [];
        if ($patchText === '') {
            return $patches;
        }

        $lines = explode("\n", $patchText);

        while ($lines !== []) {
            $line = $lines[0];
            if (preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@$/', $line, $matches) !== 1) {
                throw new \InvalidArgumentException('Invalid patch header: ' . $line);
            }

            $patch = new PatchObject();
            $patch->setStart1((int) $matches[1]);
            if ($matches[2] === '') {
                $patch->setStart1($patch->getStart1() - 1);
                $patch->setLength1(1);
            } elseif ($matches[2] === '0') {
                $patch->setLength1(0);
            } else {
                $patch->setStart1($patch->getStart1() - 1);
                $patch->setLength1((int) $matches[2]);
            }

            $patch->setStart2((int) $matches[3]);
            if ($matches[4] === '') {
                $patch->setStart2($patch->getStart2() - 1);
                $patch->setLength2(1);
            } elseif ($matches[4] === '0') {
                $patch->setLength2(0);
            } else {
                $patch->setStart2($patch->getStart2() - 1);
                $patch->setLength2((int) $matches[4]);
            }

            $patches[] = $patch;
            array_shift($lines);

            while ($lines !== []) {
                $line = $lines[0];
                $sign = $line === '' ? '' : Utils::charAt($line, 0);
                $body = Utils::unescape(Utils::substring($line, 1));

                switch ($sign) {
                    case '+':
                        $patch->appendChanges([Diff::INSERT, $body]);
                        break;
                    case '-':
                        $patch->appendChanges([Diff::DELETE, $body]);
                        break;
                    case ' ':
                        $patch->appendChanges([Diff::EQUAL, $body]);
                        break;
                    case '@':
                        // The next patch header terminates this one.
                        break 2;
                    case '':
                        // A blank line; ignore it.
                        break;
                    default:
                        throw new \UnexpectedValueException('Invalid patch mode: ' . $sign);
                }

                array_shift($lines);
            }
        }

        return $patches;
    }

    /**
     * Apply a list of patches to a text.
     *
     * @param list<PatchObject> $patches
     *
     * @return array{0: string, 1: list<bool>} The patched text and, for each
     *         patch, whether it was applied.
     */
    public function apply(array $patches, string $text): array
    {
        if ($patches === []) {
            return [$text, []];
        }

        // Work on copies so the caller's patches are left untouched.
        $patches = $this->deepCopy($patches);

        $nullPadding = $this->addPadding($patches);
        $text = $nullPadding . $text . $nullPadding;

        $this->splitMax($patches);

        // Tracks how far the previous patch actually landed from where it was
        // expected, so later patches can be shifted to match.
        $delta = 0;
        $results = [];
        $maxBits = $this->matcher->getMaxBits();

        foreach ($patches as $patch) {
            $expectedLoc = $patch->getStart2() + $delta;
            $diffs = $patch->getChanges();
            $text1 = $this->diff->text1($diffs);
            $text1Length = Utils::length($text1);
            $endLoc = -1;

            if ($text1Length > $maxBits) {
                // Only an oversized pattern arrives here, from a huge deletion.
                $startLoc = $this->matcher->main($text, Utils::substring($text1, 0, $maxBits), $expectedLoc);
                if ($startLoc !== -1) {
                    $endLoc = $this->matcher->main(
                        $text,
                        Utils::substring($text1, -$maxBits),
                        $expectedLoc + $text1Length - $maxBits,
                    );
                    if ($endLoc === -1 || $startLoc >= $endLoc) {
                        // No usable trailing context; abandon the patch.
                        $startLoc = -1;
                    }
                }
            } else {
                $startLoc = $this->matcher->main($text, $text1, $expectedLoc);
            }

            if ($startLoc === -1) {
                $results[] = false;
                // Account for this patch's size difference in later offsets.
                $delta -= $patch->getLength2() - $patch->getLength1();
                continue;
            }

            $results[] = true;
            $delta = $startLoc - $expectedLoc;

            if ($endLoc === -1) {
                $text2 = Utils::substring($text, $startLoc, $text1Length);
            } else {
                $text2 = Utils::substring($text, $startLoc, $endLoc + $maxBits - $startLoc);
            }

            if ($text1 === $text2) {
                // Exact match: drop the replacement straight in.
                $text = Utils::substring($text, 0, $startLoc)
                    . $this->diff->text2($diffs)
                    . Utils::substring($text, $startLoc + $text1Length);
                continue;
            }

            // Imperfect match: align the patch's source with the text we found.
            $matchDiffs = $this->diff->main($text1, $text2, false);

            if ($text1Length > $maxBits && $this->diff->levenshtein($matchDiffs) / $text1Length > $this->deleteThreshold) {
                // The end points line up but the content is too different.
                $results[count($results) - 1] = false;
                continue;
            }

            $matchDiffs = $this->diff->cleanupSemanticLossless($matchDiffs);
            $index1 = 0;
            foreach ($diffs as [$operation, $data]) {
                if ($operation !== Diff::EQUAL) {
                    $index2 = $this->diff->xIndex($matchDiffs, $index1);
                    if ($operation === Diff::INSERT) {
                        $text = Utils::substring($text, 0, $startLoc + $index2)
                            . $data
                            . Utils::substring($text, $startLoc + $index2);
                    } elseif ($operation === Diff::DELETE) {
                        $text = Utils::substring($text, 0, $startLoc + $index2)
                            . Utils::substring($text, $startLoc + $this->diff->xIndex($matchDiffs, $index1 + Utils::length($data)));
                    }
                }

                if ($operation !== Diff::DELETE) {
                    $index1 += Utils::length($data);
                }
            }
        }

        $padding = Utils::length($nullPadding);
        $text = Utils::substring($text, $padding, -$padding);

        return [$text, $results];
    }

    /**
     * Break up any patch longer than the matcher's bit limit, so it can still
     * be located.
     *
     * @param list<PatchObject> $patches
     */
    public function splitMax(array &$patches): void
    {
        $patchSize = $this->matcher->getMaxBits();
        if ($patchSize === 0) {
            return;
        }

        for ($i = 0; $i < count($patches); $i++) {
            if ($patches[$i]->getLength1() <= $patchSize) {
                continue;
            }

            $bigPatch = $patches[$i];
            array_splice($patches, $i, 1);
            $i--;

            $start1 = $bigPatch->getStart1();
            $start2 = $bigPatch->getStart2();
            $preContext = '';
            $bigPatchDiffs = $bigPatch->getChanges();

            while ($bigPatchDiffs !== []) {
                $patch = new PatchObject();
                $preContextLength = Utils::length($preContext);
                $patch->setStart1($start1 - $preContextLength);
                $patch->setStart2($start2 - $preContextLength);

                if ($preContext !== '') {
                    $patch->setLength1($preContextLength);
                    $patch->setLength2($preContextLength);
                    $patch->appendChanges([Diff::EQUAL, $preContext]);
                }

                $empty = true;
                while ($bigPatchDiffs !== [] && $patch->getLength1() < $patchSize - $this->margin) {
                    [$operation, $text] = $bigPatchDiffs[0];
                    $textLength = Utils::length($text);

                    if ($operation === Diff::INSERT) {
                        // Insertions cost no source characters, so they are safe.
                        $patch->setLength2($patch->getLength2() + $textLength);
                        $start2 += $textLength;
                        $patch->appendChanges(array_shift($bigPatchDiffs));
                        $empty = false;
                    } elseif (
                        $operation === Diff::DELETE
                        && count($patch->getChanges()) === 1
                        && $patch->getChanges()[0][0] === Diff::EQUAL
                        && 2 * $patchSize < $textLength
                    ) {
                        // A huge deletion: keep it in one piece.
                        $patch->setLength1($patch->getLength1() + $textLength);
                        $start1 += $textLength;
                        array_shift($bigPatchDiffs);
                        $patch->appendChanges([$operation, $text]);
                        $empty = false;
                    } else {
                        // Deletion or equality: take only as much as fits.
                        $taken = Utils::substring($text, 0, $patchSize - $patch->getLength1() - $this->margin);
                        $takenLength = Utils::length($taken);
                        $patch->setLength1($patch->getLength1() + $takenLength);
                        $start1 += $takenLength;

                        if ($operation === Diff::EQUAL) {
                            $patch->setLength2($patch->getLength2() + $takenLength);
                            $start2 += $takenLength;
                        } else {
                            $empty = false;
                        }

                        if ($taken === $bigPatchDiffs[0][1]) {
                            array_shift($bigPatchDiffs);
                        } else {
                            $bigPatchDiffs[0][1] = Utils::substring($bigPatchDiffs[0][1], $takenLength);
                        }
                        $patch->appendChanges([$operation, $taken]);
                    }
                }

                // Carry the tail of this patch forward as leading context.
                $preContext = Utils::substring($this->diff->text2($patch->getChanges()), -$this->margin);

                // Close the patch with trailing context from the remaining diff.
                $postContext = Utils::substring($this->diff->text1($bigPatchDiffs), 0, $this->margin);
                if ($postContext !== '') {
                    $patch->setLength1($patch->getLength1() + Utils::length($postContext));
                    $patch->setLength2($patch->getLength2() + Utils::length($postContext));

                    $changes = $patch->getChanges();
                    if ($changes !== [] && $changes[count($changes) - 1][0] === Diff::EQUAL) {
                        $changes[count($changes) - 1][1] .= $postContext;
                        $patch->setChanges($changes);
                    } else {
                        $patch->appendChanges([Diff::EQUAL, $postContext]);
                    }
                }

                if (!$empty) {
                    $i++;
                    array_splice($patches, $i, 0, [$patch]);
                }
            }
        }
    }

    /**
     * Pad the start and end of the text with characters that will not appear
     * in it, so edge-of-document patches have something to match against.
     *
     * @param list<PatchObject> $patches
     *
     * @return string The padding that was added to each side.
     */
    public function addPadding(array &$patches): string
    {
        $paddingLength = $this->margin;
        $nullPadding = '';
        for ($i = 1; $i <= $paddingLength; $i++) {
            $nullPadding .= Utils::charFromCode($i);
        }

        foreach ($patches as $patch) {
            $patch->setStart1($patch->getStart1() + $paddingLength);
            $patch->setStart2($patch->getStart2() + $paddingLength);
        }

        $first = $patches[0];
        $this->padPatchStart($first, $paddingLength, $nullPadding);

        $last = $patches[count($patches) - 1];
        $this->padPatchEnd($last, $paddingLength, $nullPadding);

        return $nullPadding;
    }

    private function padPatchStart(PatchObject $patch, int $paddingLength, string $nullPadding): void
    {
        $changes = $patch->getChanges();

        if ($changes === [] || $changes[0][0] !== Diff::EQUAL) {
            array_unshift($changes, [Diff::EQUAL, $nullPadding]);
            $patch->setStart1($patch->getStart1() - $paddingLength);
            $patch->setStart2($patch->getStart2() - $paddingLength);
            $patch->setLength1($patch->getLength1() + $paddingLength);
            $patch->setLength2($patch->getLength2() + $paddingLength);
        } elseif ($paddingLength > Utils::length($changes[0][1])) {
            $extra = $paddingLength - Utils::length($changes[0][1]);
            $changes[0][1] = Utils::substring($nullPadding, Utils::length($changes[0][1])) . $changes[0][1];
            $patch->setStart1($patch->getStart1() - $extra);
            $patch->setStart2($patch->getStart2() - $extra);
            $patch->setLength1($patch->getLength1() + $extra);
            $patch->setLength2($patch->getLength2() + $extra);
        }

        $patch->setChanges($changes);
    }

    private function padPatchEnd(PatchObject $patch, int $paddingLength, string $nullPadding): void
    {
        $changes = $patch->getChanges();
        $last = count($changes) - 1;

        if ($changes === [] || $changes[$last][0] !== Diff::EQUAL) {
            $changes[] = [Diff::EQUAL, $nullPadding];
            $patch->setLength1($patch->getLength1() + $paddingLength);
            $patch->setLength2($patch->getLength2() + $paddingLength);
        } elseif ($paddingLength > Utils::length($changes[$last][1])) {
            $extra = $paddingLength - Utils::length($changes[$last][1]);
            $changes[$last][1] .= Utils::substring($nullPadding, 0, $extra);
            $patch->setLength1($patch->getLength1() + $extra);
            $patch->setLength2($patch->getLength2() + $extra);
        }

        $patch->setChanges($changes);
    }

    /**
     * @param list<PatchObject> $patches
     *
     * @return list<PatchObject>
     */
    private function deepCopy(array $patches): array
    {
        return array_map(static fn (PatchObject $patch): PatchObject => clone $patch, $patches);
    }
}
