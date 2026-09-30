<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Diff;
use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately awkward inputs and configurations: degenerate settings, control
 * characters that collide with the library's own internal padding, byte-level
 * edge cases and the exact boundaries of the built-in speed-ups.
 */
final class NonStandardTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    #[DataProvider('invalidMargins')]
    public function testPatchMarginBelowOneIsRejected(int $margin): void
    {
        // A margin of 0 never lets the context window grow, which makes
        // patch_make and patch_apply spin forever on repetitive text (Google's
        // ports share the flaw), and a zero-length stretch of padding would
        // truncate the applied text. Reject it up front instead.
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->Patch_Margin = $margin;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMargins(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'very negative' => [-100];
    }

    public function testPatchMarginOfOneIsAccepted(): void
    {
        $this->simpleDiff->Patch_Margin = 1;

        $patches = $this->simpleDiff->patch_make('The quick brown fox.', 'The quick red fox.');
        [$result] = $this->simpleDiff->patch_apply($patches, 'The quick brown fox.');

        self::assertSame('The quick red fox.', $result);
    }

    public function testZeroThresholdOnlyAcceptsAnExactMatchAtTheLocation(): void
    {
        $this->simpleDiff->Match_Threshold = 0.0;

        // A perfect match sitting exactly at `loc` is accepted ...
        self::assertSame(0, $this->simpleDiff->match_main('brown fox', 'brown', 0));

        // ... but one even slightly away is not.
        self::assertSame(-1, $this->simpleDiff->match_main('The quick brown fox', 'brown', 0));
    }

    public function testThresholdOneStillObeysTheErrorBudget(): void
    {
        $this->simpleDiff->Match_Threshold = 1.0;

        // Bitap allows at most len(pattern) - 1 errors, so a pattern sharing no
        // characters with the text cannot match however loose the threshold is.
        self::assertSame(-1, $this->simpleDiff->match_main('abcdef', 'xyz', 3));
    }

    public function testTextContainingThePaddingCharactersStillPatchesCorrectly(): void
    {
        // patch_apply wraps the text in the control characters 1-4; a document
        // that already contains them must not be confused by the padding.
        $padding = "\x01\x02\x03\x04";
        $before = 'start' . $padding . ' middle end';
        $after = 'start' . $padding . ' MIDDLE end';

        $patches = $this->simpleDiff->patch_make($before, $after);
        [$result, $applied] = $this->simpleDiff->patch_apply($patches, $before);

        self::assertSame($after, $result);
        self::assertNotContains(false, $applied);
    }

    public function testNullByteIsTreatedAsAnOrdinaryCharacter(): void
    {
        self::assertSame(1, $this->simpleDiff->match_main("a\0b", "\0", 0));

        $changes = $this->simpleDiff->diff_main("a\0b", "a\0c");
        self::assertSame("a\0b", $this->simpleDiff->diff_text1($changes));
        self::assertSame("a\0c", $this->simpleDiff->diff_text2($changes));
    }

    public function testALongSingleLineStillTriggersLineMode(): void
    {
        $before = str_repeat('x', 150) . 'END';
        $after = str_repeat('x', 150) . 'FIN';

        $changes = $this->simpleDiff->diff_main($before, $after);

        self::assertSame($before, $this->simpleDiff->diff_text1($changes));
        self::assertSame($after, $this->simpleDiff->diff_text2($changes));
    }

    public function testLineModeBoundaryIsHandledOnBothSides(): void
    {
        // The line-level pre-pass is only used when both texts exceed 100 chars.
        foreach ([100, 101, 102] as $length) {
            $before = str_repeat('a', $length);
            $after = str_repeat('a', $length - 1) . 'b';

            foreach ([true, false] as $checklines) {
                $changes = $this->simpleDiff->diff_main($before, $after, $checklines);

                self::assertSame($before, $this->simpleDiff->diff_text1($changes), "len={$length}");
                self::assertSame($after, $this->simpleDiff->diff_text2($changes), "len={$length}");
            }
        }
    }

    public function testWhitespaceOnlyTexts(): void
    {
        foreach ([
            ["\n\n\n", "\n\n"],
            ['     ', '  '],
            ["\t\t", "\t \t"],
            ["\r\n\r\n", "\r\n"],
            ['', "\n"],
        ] as [$before, $after]) {
            $changes = $this->simpleDiff->diff_main($before, $after);

            self::assertSame($before, $this->simpleDiff->diff_text1($changes));
            self::assertSame($after, $this->simpleDiff->diff_text2($changes));
        }
    }

    public function testZeroEditCostDisablesEqualityElimination(): void
    {
        $this->simpleDiff->Diff_EditCost = 0;

        $diffs = [
            [Diff::DELETE, 'A'],
            [Diff::EQUAL, 'X'],
            [Diff::INSERT, 'C'],
            [Diff::DELETE, 'B'],
        ];

        $this->simpleDiff->diff_cleanupEfficiency($diffs);

        // With an edit cost of 0, no equality can ever be "cheaper than an edit".
        self::assertSame([
            [Diff::DELETE, 'A'],
            [Diff::EQUAL, 'X'],
            [Diff::INSERT, 'C'],
            [Diff::DELETE, 'B'],
        ], $diffs);
    }

    public function testDeltaRoundTripsForAstralPlaneCharacters(): void
    {
        $diffs = [
            [Diff::EQUAL, 'go '],
            [Diff::INSERT, '🚀👍'],
            [Diff::DELETE, '🚀'],
        ];
        $before = $this->simpleDiff->diff_text1($diffs);

        $delta = $this->simpleDiff->diff_toDelta($diffs);

        self::assertSame($diffs, $this->simpleDiff->diff_fromDelta($before, $delta));
    }
}
