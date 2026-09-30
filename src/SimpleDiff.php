<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * The public entry point for the library.
 *
 * This class wires together the diff, match and patch engines and exposes the
 * familiar `diff_*`, `match_*` and `patch_*` methods so that code written for
 * other Diff Match and Patch ports keeps working unchanged.
 *
 * Tuning knobs are available as magic properties, mirroring the original
 * library:
 *
 * @property float $Diff_Timeout          Seconds to spend on a diff before giving up (0 = no limit).
 * @property int   $Diff_EditCost         Cost of an empty edit when simplifying a diff.
 * @property float $Match_Threshold       Score at which a match is rejected (0.0 = exact, 1.0 = very loose).
 * @property int   $Match_Distance        Distance from the expected location that scores 1.0 worse.
 * @property int   $Match_MaxBits         Length limit for Bitap patterns.
 * @property float $Patch_DeleteThreshold Similarity required before a large deletion is accepted.
 * @property int   $Patch_Margin          Context size (in characters) carried by each patch (minimum 1).
 */
class SimpleDiff
{
    /**
     * Operation codes used in diff arrays. A diff is a list of
     * `[operation, text]` pairs:
     *
     *     [
     *         [SimpleDiff::DIFF_DELETE, 'Hello'],
     *         [SimpleDiff::DIFF_INSERT, 'Goodbye'],
     *         [SimpleDiff::DIFF_EQUAL,  ' world.'],
     *     ]
     */
    public const DIFF_DELETE = Diff::DELETE;
    public const DIFF_INSERT = Diff::INSERT;
    public const DIFF_EQUAL = Diff::EQUAL;

    private readonly Diff $diff;
    private readonly Matcher $matcher;
    private readonly Patch $patch;

    public function __construct()
    {
        $this->diff = new Diff();
        $this->matcher = new Matcher();
        $this->patch = new Patch($this->diff, $this->matcher);
    }

    // -- Diff -----------------------------------------------------------------

    /**
     * Diff two texts and return the list of changes.
     *
     * @param string|null $text1      Old text.
     * @param string|null $text2      New text.
     * @param bool        $checklines Whether to try a line-level diff first for
     *                                large inputs (faster, occasionally less
     *                                optimal).
     *
     * @return list<array{0: int, 1: string}>
     */
    public function diff_main(?string $text1, ?string $text2, bool $checklines = true): array
    {
        return $this->diff->main($text1, $text2, $checklines);
    }

    /**
     * Reduce edits by dropping semantically trivial equalities, in place.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_cleanupSemantic(array &$diffs): void
    {
        $diffs = $this->diff->cleanupSemantic($diffs);
    }

    /**
     * Shift edits onto word or line boundaries where possible, in place.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_cleanupSemanticLossless(array &$diffs): void
    {
        $diffs = $this->diff->cleanupSemanticLossless($diffs);
    }

    /**
     * Reduce edits by dropping operationally trivial equalities, in place.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_cleanupEfficiency(array &$diffs): void
    {
        $diffs = $this->diff->cleanupEfficiency($diffs);
    }

    /**
     * Reorder and merge like edit sections, in place.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_cleanupMerge(array &$diffs): void
    {
        $diffs = $this->diff->cleanupMerge($diffs);
    }

    /**
     * Number of characters shared at the start of two texts.
     */
    public function diff_commonPrefix(string $text1, string $text2): int
    {
        return $this->diff->getToolkit()->commonPrefix($text1, $text2);
    }

    /**
     * Number of characters shared at the end of two texts.
     */
    public function diff_commonSuffix(string $text1, string $text2): int
    {
        return $this->diff->getToolkit()->commonSuffix($text1, $text2);
    }

    /**
     * Collapse both texts to one character per line.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public function diff_linesToChars(string $text1, string $text2): array
    {
        return $this->diff->getToolkit()->linesToChars($text1, $text2);
    }

    /**
     * Expand a diff over line hashes back into real text.
     *
     * @param list<array{0: int, 1: string}> $diffs
     * @param list<string>                   $lineArray
     *
     * @return list<array{0: int, 1: string}>
     */
    public function diff_charsToLines(array $diffs, array $lineArray): array
    {
        return $this->diff->getToolkit()->charsToLines($diffs, $lineArray);
    }

    /**
     * Levenshtein distance of a diff.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_levenshtein(array $diffs): int
    {
        return $this->diff->levenshtein($diffs);
    }

    /**
     * Encode a diff as a tab-separated delta string.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_toDelta(array $diffs): string
    {
        return $this->diff->toDelta($diffs);
    }

    /**
     * Rebuild a diff from a source text and a delta string.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function diff_fromDelta(string $text1, string $delta): array
    {
        return $this->diff->fromDelta($text1, $delta);
    }

    /**
     * Translate an offset in text1 into the matching offset in text2.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_xIndex(array $diffs, int $loc): int
    {
        return $this->diff->xIndex($diffs, $loc);
    }

    /**
     * Render a diff as an HTML report.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_prettyHtml(array $diffs): string
    {
        return $this->diff->prettyHtml($diffs);
    }

    /**
     * Source text of a diff (equalities and deletions).
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_text1(array $diffs): string
    {
        return $this->diff->text1($diffs);
    }

    /**
     * Destination text of a diff (equalities and insertions).
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    public function diff_text2(array $diffs): string
    {
        return $this->diff->text2($diffs);
    }

    // -- Match ----------------------------------------------------------------

    /**
     * Find the best occurrence of $pattern in $text near $loc.
     *
     * @return int Offset of the match, or -1.
     */
    public function match_main(?string $text, ?string $pattern, int $loc = 0): int
    {
        return $this->matcher->main($text, $pattern, $loc);
    }

    // -- Patch ----------------------------------------------------------------

    /**
     * Compute the patches that turn one text into another.
     *
     * @param string|list<array{0: int, 1: string}>      $a
     * @param string|list<array{0: int, 1: string}>|null $b
     * @param list<array{0: int, 1: string}>|null        $c
     *
     * @return list<PatchObject>
     */
    public function patch_make(string|array $a, string|array|null $b = null, ?array $c = null): array
    {
        return $this->patch->make($a, $b, $c);
    }

    /**
     * Serialise patches to text.
     *
     * @param list<PatchObject> $patches
     */
    public function patch_toText(array $patches): string
    {
        return $this->patch->toText($patches);
    }

    /**
     * Parse patches from their text form.
     *
     * @return list<PatchObject>
     */
    public function patch_fromText(string $text): array
    {
        return $this->patch->fromText($text);
    }

    /**
     * Apply patches to a text.
     *
     * @param list<PatchObject> $patches
     *
     * @return array{0: string, 1: list<bool>}
     */
    public function patch_apply(array $patches, string $text): array
    {
        return $this->patch->apply($patches, $text);
    }

    /**
     * Pad patches (and their surrounding text) so edge-of-document patches can
     * still match.
     *
     * @param list<PatchObject> $patches
     *
     * @return string The padding added to each side.
     */
    public function patch_addPadding(array &$patches): string
    {
        return $this->patch->addPadding($patches);
    }

    /**
     * Split any patch longer than the matcher's limit into smaller patches.
     *
     * @param list<PatchObject> $patches
     */
    public function patch_splitMax(array &$patches): void
    {
        $this->patch->splitMax($patches);
    }

    // -- Tuning properties ----------------------------------------------------

    /**
     * @throws \UnexpectedValueException When the property is unknown.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'Diff_Timeout' => $this->diff->getTimeout(),
            'Diff_EditCost' => $this->diff->getEditCost(),
            'Match_Threshold' => $this->matcher->getThreshold(),
            'Match_Distance' => $this->matcher->getDistance(),
            'Match_MaxBits' => $this->matcher->getMaxBits(),
            'Patch_DeleteThreshold' => $this->patch->getDeleteThreshold(),
            'Patch_Margin' => $this->patch->getMargin(),
            default => throw new \UnexpectedValueException('Unknown property: ' . $name),
        };
    }

    /**
     * @throws \UnexpectedValueException When the property is unknown.
     */
    public function __set(string $name, mixed $value): void
    {
        switch ($name) {
            case 'Diff_Timeout':
                $this->diff->setTimeout($this->toFloat($value, $name));
                return;
            case 'Diff_EditCost':
                $this->diff->setEditCost($this->toInt($value, $name));
                return;
            case 'Match_Threshold':
                $this->matcher->setThreshold($this->toFloat($value, $name));
                return;
            case 'Match_Distance':
                $this->matcher->setDistance($this->toInt($value, $name));
                return;
            case 'Match_MaxBits':
                $this->matcher->setMaxBits($this->toInt($value, $name));
                return;
            case 'Patch_DeleteThreshold':
                $this->patch->setDeleteThreshold($this->toFloat($value, $name));
                return;
            case 'Patch_Margin':
                $this->patch->setMargin($this->toInt($value, $name));
                return;
            default:
                throw new \UnexpectedValueException('Unknown property: ' . $name);
        }
    }

    /**
     * Coerce a scalar tuning value to a float.
     *
     * @throws \InvalidArgumentException When the value is not a scalar.
     */
    private function toFloat(mixed $value, string $property): float
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Property %s expects a number, %s given.',
                $property,
                get_debug_type($value),
            ));
        }

        return (float) $value;
    }

    /**
     * Coerce a scalar tuning value to an integer.
     *
     * @throws \InvalidArgumentException When the value is not a scalar.
     */
    private function toInt(mixed $value, string $property): int
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Property %s expects an integer, %s given.',
                $property,
                get_debug_type($value),
            ));
        }

        return (int) $value;
    }
}
