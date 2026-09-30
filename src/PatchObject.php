<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff;

/**
 * A single patch: the edits that transform one region of a document,
 * together with the position and size of that region in both the old and the
 * new text.
 *
 * The edit list uses the same `[operation, text]` shape as a diff, where the
 * operation is one of {@see Diff::DELETE}, {@see Diff::INSERT} or
 * {@see Diff::EQUAL}.
 */
class PatchObject
{
    /** @var list<array{0: int, 1: string}> */
    private array $changes = [];

    /** Offset of the patch within the source text (0-based, may be negative after padding). */
    private ?int $start1 = null;

    /** Offset of the patch within the destination text (0-based). */
    private ?int $start2 = null;

    /** Number of source characters covered by the patch. */
    private int $length1 = 0;

    /** Number of destination characters covered by the patch. */
    private int $length2 = 0;

    /**
     * @return list<array{0: int, 1: string}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * @param list<array{0: int, 1: string}> $changes
     */
    public function setChanges(array $changes): void
    {
        $this->changes = $changes;
    }

    /**
     * @param array{0: int, 1: string} $change
     */
    public function appendChanges(array $change): void
    {
        $this->changes[] = $change;
    }

    /**
     * @param array{0: int, 1: string} $change
     */
    public function prependChanges(array $change): void
    {
        array_unshift($this->changes, $change);
    }

    public function getStart1(): int
    {
        return $this->start1 ?? 0;
    }

    public function setStart1(int $start1): void
    {
        $this->start1 = $start1;
    }

    public function getStart2(): int
    {
        return $this->start2 ?? 0;
    }

    public function setStart2(int $start2): void
    {
        $this->start2 = $start2;
    }

    public function getLength1(): int
    {
        return $this->length1;
    }

    public function setLength1(int $length1): void
    {
        $this->length1 = $length1;
    }

    public function getLength2(): int
    {
        return $this->length2;
    }

    public function setLength2(int $length2): void
    {
        $this->length2 = $length2;
    }

    /**
     * Render the patch in the Unidiff-like text format used by the library.
     */
    public function __toString(): string
    {
        return $this->toText();
    }

    /**
     * Render the patch in the Unidiff-like text format used by the library.
     *
     * The header mimics GNU diff, e.g. `@@ -382,8 +481,9 @@`, with 1-based
     * coordinates. The body escapes every line with {@see Utils::escape()}.
     */
    public function toText(): string
    {
        $header = sprintf(
            "@@ -%s +%s @@\n",
            $this->formatCoordinates($this->getStart1(), $this->getLength1()),
            $this->formatCoordinates($this->getStart2(), $this->getLength2()),
        );

        $body = '';
        foreach ($this->changes as [$operation, $text]) {
            $body .= match ($operation) {
                Diff::INSERT => '+',
                Diff::DELETE => '-',
                default => ' ',
            };
            $body .= Utils::escape($text) . "\n";
        }

        return $header . $body;
    }

    /**
     * Format a patch range the way GNU diff does.
     */
    private function formatCoordinates(int $start, int $length): string
    {
        if ($length === 0) {
            return $start . ',0';
        }

        if ($length === 1) {
            return (string) ($start + 1);
        }

        return ($start + 1) . ',' . $length;
    }
}
