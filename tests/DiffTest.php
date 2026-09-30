<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Diff;
use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    public function testReadmeExample(): void
    {
        $text1 = 'The quick brown fox jumps over the lazy dog.';
        $text2 = 'That quick brown fox jumped over a lazy dog.';

        self::assertSame([
            [Diff::EQUAL, 'Th'],
            [Diff::DELETE, 'e'],
            [Diff::INSERT, 'at'],
            [Diff::EQUAL, ' quick brown fox jump'],
            [Diff::DELETE, 's'],
            [Diff::INSERT, 'ed'],
            [Diff::EQUAL, ' over '],
            [Diff::DELETE, 'the'],
            [Diff::INSERT, 'a'],
            [Diff::EQUAL, ' lazy dog.'],
        ], $this->simpleDiff->diff_main($text1, $text2, false));
    }

    public function testEqualTextsProduceASingleEquality(): void
    {
        self::assertSame([[Diff::EQUAL, 'abc']], $this->simpleDiff->diff_main('abc', 'abc'));
    }

    public function testEqualEmptyTextsProduceNothing(): void
    {
        self::assertSame([], $this->simpleDiff->diff_main('', ''));
    }

    public function testInsertionOnly(): void
    {
        self::assertSame([[Diff::INSERT, 'abc']], $this->simpleDiff->diff_main('', 'abc'));
    }

    public function testDeletionOnly(): void
    {
        self::assertSame([[Diff::DELETE, 'abc']], $this->simpleDiff->diff_main('abc', ''));
    }

    public function testMiddleInsertionIsSurroundedByEqualities(): void
    {
        self::assertSame([
            [Diff::EQUAL, 'ab'],
            [Diff::INSERT, '123'],
            [Diff::EQUAL, 'c'],
        ], $this->simpleDiff->diff_main('abc', 'ab123c'));
    }

    public function testSubstitution(): void
    {
        self::assertSame([
            [Diff::EQUAL, 'ab'],
            [Diff::DELETE, 'c'],
            [Diff::INSERT, 'd'],
        ], $this->simpleDiff->diff_main('abc', 'abd'));
    }

    public function testMultibyteCharactersAreNeverSplit(): void
    {
        self::assertSame([
            [Diff::EQUAL, 'h'],
            [Diff::DELETE, 'é'],
            [Diff::INSERT, 'a'],
            [Diff::EQUAL, 'llo'],
        ], $this->simpleDiff->diff_main('héllo', 'hallo'));

        // Emoji share leading bytes; they must still be treated as whole units.
        self::assertSame(
            [[Diff::DELETE, '👍'], [Diff::INSERT, '👎']],
            $this->simpleDiff->diff_main('👍', '👎'),
        );
    }

    public function testDiffCanBeReassembledIntoBothTexts(): void
    {
        $text1 = "The rain in Spain\nfalls mainly on the plain.\n";
        $text2 = "The rain in Spain\nfalls gently on the plain.\n";

        $diffs = $this->simpleDiff->diff_main($text1, $text2);

        self::assertSame($text1, $this->simpleDiff->diff_text1($diffs));
        self::assertSame($text2, $this->simpleDiff->diff_text2($diffs));
    }

    public function testLineModeHandlesLargeInputs(): void
    {
        $lines = [];
        for ($i = 0; $i < 120; $i++) {
            $lines[] = "line {$i} with enough content to exceed the threshold";
        }
        $text1 = implode("\n", $lines);
        $text2 = implode("\n", array_merge(
            array_slice($lines, 0, 60),
            ['an inserted line that did not exist before'],
            array_slice($lines, 61),
        ));

        $diffs = $this->simpleDiff->diff_main($text1, $text2);

        self::assertSame($text1, $this->simpleDiff->diff_text1($diffs));
        self::assertSame($text2, $this->simpleDiff->diff_text2($diffs));
    }

    public function testCommonPrefixAndSuffix(): void
    {
        self::assertSame(3, $this->simpleDiff->diff_commonPrefix('abcdef', 'abcxyz'));
        self::assertSame(3, $this->simpleDiff->diff_commonSuffix('xyzdef', 'abcdef'));
        self::assertSame(0, $this->simpleDiff->diff_commonPrefix('é', 'è'));
    }

    public function testCleanupSemantic(): void
    {
        $diffs = [
            [Diff::EQUAL, 'The c'],
            [Diff::INSERT, 'ow and the c'],
            [Diff::EQUAL, 'at.'],
        ];

        $this->simpleDiff->diff_cleanupSemantic($diffs);

        self::assertSame([
            [Diff::EQUAL, 'The '],
            [Diff::INSERT, 'cow and the '],
            [Diff::EQUAL, 'cat.'],
        ], $diffs);
    }

    public function testCleanupSemanticLosslessShiftsEditsOntoWordBoundaries(): void
    {
        $diffs = [
            [Diff::EQUAL, 'The c'],
            [Diff::INSERT, 'at c'],
            [Diff::EQUAL, 'ame.'],
        ];

        $this->simpleDiff->diff_cleanupSemanticLossless($diffs);

        self::assertSame([
            [Diff::EQUAL, 'The '],
            [Diff::INSERT, 'cat '],
            [Diff::EQUAL, 'came.'],
        ], $diffs);
    }

    public function testCleanupEfficiencyRemovesCostlyEqualities(): void
    {
        $diffs = [
            [Diff::DELETE, 'A'],
            [Diff::EQUAL, 'X'],
            [Diff::INSERT, 'C'],
            [Diff::DELETE, 'B'],
        ];

        $this->simpleDiff->diff_cleanupEfficiency($diffs);

        self::assertSame([
            [Diff::DELETE, 'AXB'],
            [Diff::INSERT, 'XC'],
        ], $diffs);
    }

    public function testLevenshtein(): void
    {
        self::assertSame(0, $this->simpleDiff->diff_levenshtein($this->simpleDiff->diff_main('abc', 'abc')));
        self::assertSame(2, $this->simpleDiff->diff_levenshtein([[Diff::DELETE, 'ab'], [Diff::INSERT, 'cd']]));
        self::assertSame(7, $this->simpleDiff->diff_levenshtein(
            $this->simpleDiff->diff_main(
                'The quick brown fox jumps over the lazy dog.',
                'That quick brown fox jumped over a lazy dog.',
            ),
        ));
    }

    public function testPrettyHtml(): void
    {
        $html = $this->simpleDiff->diff_prettyHtml([
            [Diff::EQUAL, "a<b"],
            [Diff::DELETE, 'c'],
            [Diff::INSERT, "d\ne"],
        ]);

        self::assertSame(
            '<span>a&lt;b</span><del style="background:#ffe6e6;">c</del>'
            . '<ins style="background:#e6ffe6;">d&para;<br>e</ins>',
            $html,
        );
    }

    public function testXIndex(): void
    {
        $diffs = $this->simpleDiff->diff_main('The cat', 'The big cat');

        self::assertSame(1, $this->simpleDiff->diff_xIndex($diffs, 1));
        // Index 4 is 'c', which lands at index 8 in "The big cat".
        self::assertSame(8, $this->simpleDiff->diff_xIndex($diffs, 4));
        self::assertSame(0, $this->simpleDiff->diff_xIndex($diffs, 0));
    }

    public function testXIndexInsideADeletion(): void
    {
        $diffs = [[Diff::EQUAL, 'ab'], [Diff::DELETE, 'XYZ'], [Diff::EQUAL, 'cd']];

        // Anywhere inside the deleted run maps to the end of the preceding equality.
        self::assertSame(2, $this->simpleDiff->diff_xIndex($diffs, 3));
    }

    public function testDeltaRoundTrip(): void
    {
        $cases = [
            ['', 'abc'],
            ['abc', ''],
            ['abc', 'ab123c'],
            ['The quick brown fox.', 'The quick red fox!'],
            ["multi\nline\ntext", "multi\nLINE\ntext"],
            ['café ☕', 'café 🍵'],
        ];

        foreach ($cases as [$text1, $text2]) {
            $diffs = $this->simpleDiff->diff_main($text1, $text2);
            $delta = $this->simpleDiff->diff_toDelta($diffs);

            self::assertSame($diffs, $this->simpleDiff->diff_fromDelta($text1, $delta), "round trip for {$text1} -> {$text2}");
        }

        self::assertSame("=2\t+123", $this->simpleDiff->diff_toDelta($this->simpleDiff->diff_main('ab', 'ab123')));
    }

    public function testFromDeltaRejectsLengthsThatDoNotConsumeTheText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->diff_fromDelta('abc', '=2');
    }

    public function testFromDeltaRejectsUnknownOperations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->diff_fromDelta('abc', '?1');
    }

    public function testLinesToCharsRoundTrip(): void
    {
        $text1 = "one\ntwo\nthree\n";
        $text2 = "one\nTWO\nthree\n";

        [$chars1, $chars2, $lineArray] = $this->simpleDiff->diff_linesToChars($text1, $text2);
        $diffs = $this->simpleDiff->diff_charsToLines([[Diff::EQUAL, $chars1], [Diff::DELETE, $chars2]], $lineArray);

        self::assertSame($text1, $diffs[0][1]);
        self::assertSame($text2, $diffs[1][1]);
    }

    public function testMainRejectsNullTexts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->diff_main(null, 'abc');
    }

    public function testDeletionSubstringShortcut(): void
    {
        self::assertSame([
            [Diff::EQUAL, 'ab'],
            [Diff::DELETE, '123'],
            [Diff::EQUAL, 'c'],
        ], $this->simpleDiff->diff_main('ab123c', 'abc'));
    }

    public function testLeadingDeletionShortcut(): void
    {
        self::assertSame([
            [Diff::DELETE, 'x'],
            [Diff::EQUAL, 'abc'],
        ], $this->simpleDiff->diff_main('xabc', 'abc'));
    }

    public function testHalfMatchSpeedupIsUsedForLongSharedRuns(): void
    {
        $before = 'prefix-' . str_repeat('MIDDLE', 20) . '-suffix-one';
        $after = 'prefix-' . str_repeat('MIDDLE', 20) . '-suffix-two';

        $changes = $this->simpleDiff->diff_main($before, $after, false);

        self::assertSame($before, $this->simpleDiff->diff_text1($changes));
        self::assertSame($after, $this->simpleDiff->diff_text2($changes));

        $longestEquality = max(array_map(
            static fn (array $change): int => $change[0] === Diff::EQUAL ? mb_strlen($change[1]) : 0,
            $changes,
        ));
        self::assertGreaterThanOrEqual(100, $longestEquality);
    }

    public function testExceedingTheDeadlineStillProducesAUsableDiff(): void
    {
        $before = substr(str_repeat('abcdefghij', 40), 0, 300);
        $after = substr(str_repeat('qrstuvwxyz', 40), 0, 300);

        $this->simpleDiff->Diff_Timeout = 0.0000001;

        $changes = $this->simpleDiff->diff_main($before, $after, false);

        self::assertSame($before, $this->simpleDiff->diff_text1($changes));
        self::assertSame($after, $this->simpleDiff->diff_text2($changes));
    }

    public function testCleanupMergeCombinesAdjacentEdits(): void
    {
        $diffs = [
            [Diff::INSERT, 'a'],
            [Diff::INSERT, 'b'],
            [Diff::EQUAL, ''],
            [Diff::DELETE, 'c'],
            [Diff::DELETE, 'd'],
        ];

        $this->simpleDiff->diff_cleanupMerge($diffs);

        // Adjacent edits of the same kind collapse; the (redundant but harmless)
        // empty equality separating them is left in place.
        self::assertSame([
            [Diff::INSERT, 'ab'],
            [Diff::EQUAL, ''],
            [Diff::DELETE, 'cd'],
        ], $diffs);
    }

    public function testCleanupSemanticExtractsAForwardOverlap(): void
    {
        $diffs = [
            [Diff::DELETE, 'abcxxx'],
            [Diff::INSERT, 'xxxdef'],
        ];

        $this->simpleDiff->diff_cleanupSemantic($diffs);

        self::assertSame([
            [Diff::DELETE, 'abc'],
            [Diff::EQUAL, 'xxx'],
            [Diff::INSERT, 'def'],
        ], $diffs);
    }

    public function testCleanupSemanticExtractsAReverseOverlap(): void
    {
        $diffs = [
            [Diff::DELETE, 'Ee%v7('],
            [Diff::INSERT, '0R,OfAyEe%'],
        ];

        $this->simpleDiff->diff_cleanupSemantic($diffs);

        self::assertSame([
            [Diff::INSERT, '0R,OfAy'],
            [Diff::EQUAL, 'Ee%'],
            [Diff::DELETE, 'v7('],
        ], $diffs);
    }

    public function testCleanupSemanticKeepsTheTextsIntactWhenAFollowingInsertionIsEmpty(): void
    {
        // Regression: an empty insertion makes both overlap lengths zero, and
        // `substring($x, 0, -0)` used to wipe the whole deletion, corrupting text1.
        $diffs = [
            [Diff::DELETE, 'cbd'],
            [Diff::DELETE, 'c '],
            [Diff::INSERT, 'c '],
            [Diff::DELETE, 'eg '],
            [Diff::EQUAL, 'rest'],
        ];

        $this->simpleDiff->diff_cleanupSemantic($diffs);

        self::assertSame('cbdc eg rest', $this->simpleDiff->diff_text1($diffs));
        self::assertSame('c rest', $this->simpleDiff->diff_text2($diffs));
    }

    public function testCleanupSemanticNeverLosesText(): void
    {
        // The exact pair that exposed both cleanup bugs.
        $before = 'kP-dS8Ee%v7(';
        $after = 'kP-dS80R,OfAyEe%';

        $diffs = $this->simpleDiff->diff_main($before, $after);
        $this->simpleDiff->diff_cleanupSemantic($diffs);

        self::assertSame($before, $this->simpleDiff->diff_text1($diffs));
        self::assertSame($after, $this->simpleDiff->diff_text2($diffs));
    }

    public function testCleanupEfficiencyKeepsEqualitiesWhenTheEditCostIsLow(): void
    {
        $this->simpleDiff->Diff_EditCost = 1;

        $diffs = [
            [Diff::DELETE, 'A'],
            [Diff::EQUAL, 'X'],
            [Diff::INSERT, 'C'],
            [Diff::DELETE, 'B'],
        ];

        $this->simpleDiff->diff_cleanupEfficiency($diffs);

        // The one-character equality is no longer "cheaper than an edit".
        self::assertSame([
            [Diff::DELETE, 'A'],
            [Diff::EQUAL, 'X'],
            [Diff::INSERT, 'C'],
            [Diff::DELETE, 'B'],
        ], $diffs);
    }

    public function testXIndexAtBoundaries(): void
    {
        $diffs = [[Diff::EQUAL, 'abc']];

        self::assertSame(0, $this->simpleDiff->diff_xIndex($diffs, 0));
        self::assertSame(3, $this->simpleDiff->diff_xIndex($diffs, 3));
    }

    public function testLevenshteinOfAnEmptyDiff(): void
    {
        self::assertSame(0, $this->simpleDiff->diff_levenshtein([]));
    }

    public function testPrettyHtmlKeepsMultiByteTextIntact(): void
    {
        $html = $this->simpleDiff->diff_prettyHtml([[Diff::INSERT, 'café 👍']]);

        self::assertSame('<ins style="background:#e6ffe6;">café 👍</ins>', $html);
    }

    public function testDiffMainNeverEmitsEmptyEdits(): void
    {
        // Regression: merging used to leave `[DELETE, '']` records behind, which
        // blocked later merging and produced non-minimal diffs.
        $before = 'aaabaabababbbbbaabbaababaaabbbbaabaabbabaaaaaaababbbbaba';
        $after = 'aaabaabababbaabababbbaabbaabbabaababbbbaaabbbbaabaabbabaaabbbaba';

        $diffs = $this->simpleDiff->diff_main($before, $after);

        foreach ($diffs as [, $text]) {
            self::assertNotSame('', $text, 'no empty edits may be emitted');
        }
        self::assertSame($before, $this->simpleDiff->diff_text1($diffs));
        self::assertSame($after, $this->simpleDiff->diff_text2($diffs));
        // Google's implementation yields this exact diff for these inputs.
        self::assertSame([
            [Diff::EQUAL, 'aaabaabababb'],
            [Diff::INSERT, 'aa'],
            [Diff::EQUAL, 'b'],
            [Diff::INSERT, 'aba'],
            [Diff::EQUAL, 'bb'],
            [Diff::INSERT, 'b'],
            [Diff::EQUAL, 'aabbaa'],
            [Diff::INSERT, 'b'],
            [Diff::EQUAL, 'babaa'],
            [Diff::INSERT, 'babbbbaa'],
            [Diff::EQUAL, 'abbbbaabaabbabaaa'],
            [Diff::DELETE, 'aaaabab'],
            [Diff::EQUAL, 'bbbaba'],
        ], $diffs);
    }

    public function testCleanupMergeDropsEmptyEdits(): void
    {
        $diffs = [
            [Diff::INSERT, 'a'],
            [Diff::INSERT, ''],
            [Diff::EQUAL, 'X'],
            [Diff::DELETE, ''],
            [Diff::DELETE, 'b'],
        ];
        $text1 = $this->simpleDiff->diff_text1($diffs);
        $text2 = $this->simpleDiff->diff_text2($diffs);

        $this->simpleDiff->diff_cleanupMerge($diffs);

        foreach ($diffs as [, $text]) {
            self::assertNotSame('', $text, 'no empty edits may survive a merge');
        }
        self::assertSame($text1, $this->simpleDiff->diff_text1($diffs));
        self::assertSame($text2, $this->simpleDiff->diff_text2($diffs));
    }
}
