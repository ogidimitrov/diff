<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Diff;
use OgiDimitrov\Diff\SimpleDiff;
use OgiDimitrov\Diff\PatchObject;
use PHPUnit\Framework\TestCase;

final class PatchTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    public function testReadmePatchIsProducedAndApplied(): void
    {
        $text1 = 'The quick brown fox jumps over the lazy dog.';
        $text2 = 'That quick brown fox jumped over a lazy dog.';

        $patches = $this->simpleDiff->patch_make($text1, $text2);

        self::assertSame(
            "@@ -1,11 +1,12 @@\n"
            . " Th\n"
            . "-e\n"
            . "+at\n"
            . "  quick b\n"
            . "@@ -22,18 +22,17 @@\n"
            . " jump\n"
            . "-s\n"
            . "+ed\n"
            . "  over \n"
            . "-the\n"
            . "+a\n"
            . "  laz\n",
            $this->simpleDiff->patch_toText($patches),
        );

        [$result, $flags] = $this->simpleDiff->patch_apply($patches, 'The quick red rabbit jumps over the tired tiger.');

        self::assertSame('That quick red rabbit jumped over a tired tiger.', $result);
        self::assertSame([true, true], $flags);
    }

    public function testApplyingPatchToTheOriginalTextReproducesTheTarget(): void
    {
        foreach ([
            ['', 'Hello'],
            ['Hello', ''],
            ['abc', 'ab123c'],
            ['The quick brown fox.', 'The quick red fox!'],
            ["multi\nline\ntext", "multi\nLINE\ntext"],
            ['café ☕ time', 'café 🍵 time'],
        ] as [$text1, $text2]) {
            $patches = $this->simpleDiff->patch_make($text1, $text2);
            [$result, $flags] = $this->simpleDiff->patch_apply($patches, $text1);

            self::assertSame($text2, $result, "patching {$text1} -> {$text2}");
            self::assertNotContains(false, $flags, "every patch should apply for {$text1} -> {$text2}");
        }
    }

    public function testTextRoundTripThroughFromText(): void
    {
        $patches = $this->simpleDiff->patch_make(
            'The quick brown fox jumps over the lazy dog.',
            'That quick brown fox jumped over a lazy dog.',
        );

        $text = $this->simpleDiff->patch_toText($patches);
        $reparsed = $this->simpleDiff->patch_fromText($text);

        self::assertSame($text, $this->simpleDiff->patch_toText($reparsed));
        self::assertSame(2, count($reparsed));
    }

    public function testFromTextRejectsMalformedHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->patch_fromText('not a patch header');
    }

    public function testEmptyInputs(): void
    {
        self::assertSame([], $this->simpleDiff->patch_fromText(''));
        self::assertSame('', $this->simpleDiff->patch_toText([]));
        self::assertSame(['unchanged', []], $this->simpleDiff->patch_apply([], 'unchanged'));
    }

    public function testApplyLeavesTheOriginalPatchesUntouched(): void
    {
        $patches = $this->simpleDiff->patch_make('abc', 'axc');
        $before = $this->simpleDiff->patch_toText($patches);

        $this->simpleDiff->patch_apply($patches, 'zzz abc zzz');

        self::assertSame($before, $this->simpleDiff->patch_toText($patches));
    }

    public function testPatchMakeCallStylesAgree(): void
    {
        $text1 = 'The quick brown fox jumps over the lazy dog.';
        $text2 = 'That quick brown fox jumped over a lazy dog.';

        $diffs = $this->simpleDiff->diff_main($text1, $text2);
        $this->simpleDiff->diff_cleanupSemantic($diffs);
        $this->simpleDiff->diff_cleanupEfficiency($diffs);

        $viaTexts = $this->simpleDiff->patch_toText($this->simpleDiff->patch_make($text1, $text2));
        $viaDiffOnly = $this->simpleDiff->patch_toText($this->simpleDiff->patch_make($diffs));
        $viaTextAndDiff = $this->simpleDiff->patch_toText($this->simpleDiff->patch_make($text1, $diffs));

        self::assertSame($viaTexts, $viaDiffOnly);
        self::assertSame($viaTexts, $viaTextAndDiff);
    }

    public function testPatchMakeRejectsUnknownCallFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // No argument matches any supported combination.
        $this->simpleDiff->patch_make('text1', null, []);
    }

    public function testAddPaddingReturnsMarginWidePadding(): void
    {
        $patches = $this->simpleDiff->patch_make('The quick brown fox.', 'The quick red fox.');
        $padding = $this->simpleDiff->patch_addPadding($patches);

        self::assertSame(4, mb_strlen($padding));
    }

    public function testSplitMaxBreaksUpAnOversizedPatch(): void
    {
        // A 50-character deletion in the middle of a 200-character text (small
        // enough that it is not treated as a "monster" deletion).
        $text1 = str_repeat('abcdefghij', 20);
        $text2 = substr($text1, 0, 75) . substr($text1, 125);

        $patches = $this->simpleDiff->patch_make($text1, $text2);
        $before = count($patches);
        $longest = max(array_map(
            static fn (PatchObject $patch): int => $patch->getLength1(),
            $patches,
        ));
        self::assertGreaterThan(32, $longest, 'the patch should exceed the Bitap limit');

        $this->simpleDiff->patch_splitMax($patches);

        self::assertGreaterThan($before, count($patches));
        foreach ($patches as $patch) {
            self::assertLessThanOrEqual(32, $patch->getLength1());
        }

        [$result] = $this->simpleDiff->patch_apply($patches, $text1);
        self::assertSame($text2, $result);
    }

    public function testSplitMaxKeepsAMonsterDeletionIntact(): void
    {
        // A deletion far larger than twice the limit is kept as a single chunk.
        $text1 = str_repeat('abcdefghij', 30); // 300 characters
        $text2 = substr($text1, 0, 20) . substr($text1, 280);

        $patches = $this->simpleDiff->patch_make($text1, $text2);
        $this->simpleDiff->patch_splitMax($patches);

        self::assertCount(1, $patches);
        self::assertGreaterThan(64, $patches[0]->getLength1());
    }

    public function testPatchObjectRendersTheUnidiffHeader(): void
    {
        $patch = new PatchObject();
        $patch->setStart1(23);
        $patch->setLength1(10);
        $patch->setStart2(23);
        $patch->setLength2(0);
        $patch->appendChanges([Diff::DELETE, "line one\nline two\n"]);

        self::assertSame(
            "@@ -24,10 +23,0 @@\n-line one%0Aline two%0A\n",
            $patch->toText(),
        );
        self::assertSame($patch->toText(), (string) $patch);
    }

    public function testApplyReportsFailureForAFarRemovedText(): void
    {
        $patches = $this->simpleDiff->patch_make('Hello world', 'Hello there');

        [$result, $applied] = $this->simpleDiff->patch_apply($patches, 'zzz completely different zzz');

        self::assertSame([false], $applied);
        self::assertSame('zzz completely different zzz', $result);
    }

    public function testFromTextAcceptsZeroLengthRanges(): void
    {
        $patches = $this->simpleDiff->patch_fromText("@@ -0,0 +1,3 @@\n+abc\n");

        self::assertCount(1, $patches);
        self::assertSame(0, $patches[0]->getStart1());
        self::assertSame(0, $patches[0]->getLength1());
        self::assertSame(0, $patches[0]->getStart2());
        self::assertSame(3, $patches[0]->getLength2());
    }

    public function testFromTextRejectsUnknownModeCharacters(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->simpleDiff->patch_fromText("@@ -1,1 +1,1 @@\n?bad\n");
    }

    public function testFromTextIgnoresBlankLinesBetweenPatches(): void
    {
        $patches = $this->simpleDiff->patch_fromText("@@ -1,1 +1,1 @@\n abc\n\n@@ -3,1 +3,1 @@\n def\n");

        self::assertCount(2, $patches);
    }

    public function testMultiBytePatchTextRoundTrips(): void
    {
        $patches = $this->simpleDiff->patch_make('café ☕ time', 'café 🍵 time');
        $text = $this->simpleDiff->patch_toText($patches);

        self::assertSame($text, $this->simpleDiff->patch_toText($this->simpleDiff->patch_fromText($text)));
        self::assertStringContainsString('%E2%98%95', $text); // the coffee cup, percent-encoded
    }

    public function testAddPaddingGrowsShortEqualities(): void
    {
        $patch = new PatchObject();
        $patch->setChanges([[Diff::EQUAL, 'ab'], [Diff::INSERT, 'X']]);
        $patch->setStart1(3);
        $patch->setStart2(3);
        $patch->setLength1(2);
        $patch->setLength2(3);

        $patches = [$patch];
        $this->simpleDiff->patch_addPadding($patches);

        // The 2-character leading equality is grown to the full margin ...
        self::assertSame("\x03\x04ab", $patches[0]->getChanges()[0][1]);
        // ... and a fresh padding equality is appended at the end.
        self::assertSame("\x01\x02\x03\x04", $patches[0]->getChanges()[2][1]);
        self::assertSame(8, $patches[0]->getLength1());
    }

    public function testSplitMaxCanBreakUpAWholeTextRewrite(): void
    {
        $before = str_repeat('abcdefghij', 20); // 200 characters
        $after = strtoupper($before);

        $patches = $this->simpleDiff->patch_make($before, $after);
        $this->simpleDiff->patch_splitMax($patches);

        self::assertCount(8, $patches);
        foreach ($patches as $patch) {
            self::assertLessThanOrEqual(32, $patch->getLength1());
        }

        [$result] = $this->simpleDiff->patch_apply($patches, $before);
        self::assertSame($after, $result);
    }

    public function testPatchMakeWithNoEditsProducesNoPatches(): void
    {
        self::assertSame([], $this->simpleDiff->patch_make('', ''));
        self::assertSame([], $this->simpleDiff->patch_make([]));
        self::assertSame([], $this->simpleDiff->patch_make('same', 'same'));
    }

    public function testPatchMakeAcceptsTheTextTextDiffCallStyle(): void
    {
        $before = 'The quick brown fox jumps over the lazy dog.';
        $after = 'That quick brown fox jumped over a lazy dog.';

        $diffs = $this->simpleDiff->diff_main($before, $after);
        $this->simpleDiff->diff_cleanupSemantic($diffs);
        $this->simpleDiff->diff_cleanupEfficiency($diffs);

        self::assertSame(
            $this->simpleDiff->patch_toText($this->simpleDiff->patch_make($before, $after)),
            $this->simpleDiff->patch_toText($this->simpleDiff->patch_make($before, $after, $diffs)),
        );
    }
}
