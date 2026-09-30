<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Diff;
use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\TestCase;

final class SimpleDiffTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    public function testOperationConstantsMatchTheDiffEngine(): void
    {
        self::assertSame(-1, SimpleDiff::DIFF_DELETE);
        self::assertSame(1, SimpleDiff::DIFF_INSERT);
        self::assertSame(0, SimpleDiff::DIFF_EQUAL);
        self::assertSame(Diff::DELETE, SimpleDiff::DIFF_DELETE);
        self::assertSame(Diff::INSERT, SimpleDiff::DIFF_INSERT);
        self::assertSame(Diff::EQUAL, SimpleDiff::DIFF_EQUAL);
    }

    public function testTuningPropertiesHaveTheExpectedDefaults(): void
    {
        self::assertSame(1.0, $this->simpleDiff->Diff_Timeout);
        self::assertSame(4, $this->simpleDiff->Diff_EditCost);
        self::assertSame(0.5, $this->simpleDiff->Match_Threshold);
        self::assertSame(1000, $this->simpleDiff->Match_Distance);
        self::assertSame(32, $this->simpleDiff->Match_MaxBits);
        self::assertSame(0.5, $this->simpleDiff->Patch_DeleteThreshold);
        self::assertSame(4, $this->simpleDiff->Patch_Margin);
    }

    public function testTuningPropertiesCanBeChanged(): void
    {
        $this->simpleDiff->Diff_Timeout = 2.5;
        $this->simpleDiff->Diff_EditCost = 8;
        $this->simpleDiff->Match_Threshold = 0.75;
        $this->simpleDiff->Match_Distance = 500;
        $this->simpleDiff->Patch_DeleteThreshold = 0.9;
        $this->simpleDiff->Patch_Margin = 6;

        self::assertSame(2.5, $this->simpleDiff->Diff_Timeout);
        self::assertSame(8, $this->simpleDiff->Diff_EditCost);
        self::assertSame(0.75, $this->simpleDiff->Match_Threshold);
        self::assertSame(500, $this->simpleDiff->Match_Distance);
        self::assertSame(0.9, $this->simpleDiff->Patch_DeleteThreshold);
        self::assertSame(6, $this->simpleDiff->Patch_Margin);
    }

    public function testUnknownPropertyReadThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        /** @phpstan-ignore-next-line intentional unknown property */
        $this->simpleDiff->Nonexistent;
    }

    public function testUnknownPropertyWriteThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        /** @phpstan-ignore-next-line intentional unknown property */
        $this->simpleDiff->Nonexistent = 1;
    }

    public function testSettingANonScalarTuningValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentionally invalid value */
        $this->simpleDiff->Diff_Timeout = [1.0];
    }

    public function testMatchMaxBitsCannotExceedIntegerWidth(): void
    {
        $this->expectException(\RangeException::class);

        $this->simpleDiff->Match_MaxBits = PHP_INT_SIZE * 8 + 1;
    }

    public function testEndToEndDiffPatchRoundTrip(): void
    {
        $original = "The rain in Spain\nfalls mainly on the plain.\n";
        $edited = "The rain in Spain\nfalls gently on the plain.\n";

        $patches = $this->simpleDiff->patch_make($original, $edited);
        $serialised = $this->simpleDiff->patch_toText($patches);

        $restored = $this->simpleDiff->patch_apply($this->simpleDiff->patch_fromText($serialised), $original);

        self::assertSame($edited, $restored[0]);
        self::assertNotContains(false, $restored[1]);
    }

    public function testDiffTextHelpersAgreeWithTheDiff(): void
    {
        $diffs = $this->simpleDiff->diff_main('abc', 'axc');

        self::assertSame('abc', $this->simpleDiff->diff_text1($diffs));
        self::assertSame('axc', $this->simpleDiff->diff_text2($diffs));
    }

    public function testNumericStringsAndFloatsAreCoerced(): void
    {
        // Loose scalar inputs are accepted on purpose; PHPStan knows the
        // documented property types and would otherwise flag the assignment.
        /** @phpstan-ignore-next-line */
        $this->simpleDiff->Diff_Timeout = '2.5';
        /** @phpstan-ignore-next-line */
        $this->simpleDiff->Match_Threshold = '0.25';
        /** @phpstan-ignore-next-line */
        $this->simpleDiff->Patch_Margin = 3.9;

        self::assertSame(2.5, $this->simpleDiff->Diff_Timeout);
        self::assertSame(0.25, $this->simpleDiff->Match_Threshold);
        self::assertSame(3, $this->simpleDiff->Patch_Margin);
    }

    public function testScalarBooleanPropertyValuesAreAccepted(): void
    {
        /** @phpstan-ignore-next-line */
        $this->simpleDiff->Patch_DeleteThreshold = true;

        self::assertSame(1.0, $this->simpleDiff->Patch_DeleteThreshold);
    }

    public function testDiffCleanupMergeIsExposed(): void
    {
        $diffs = [[Diff::INSERT, 'a'], [Diff::INSERT, 'b']];

        $this->simpleDiff->diff_cleanupMerge($diffs);

        self::assertSame([[Diff::INSERT, 'ab']], $diffs);
    }

    public function testPatchSplittingIsExposed(): void
    {
        $before = str_repeat('abcdefghij', 20);
        $patches = $this->simpleDiff->patch_make($before, strtoupper($before));

        $initial = count($patches);
        $this->simpleDiff->patch_splitMax($patches);

        self::assertGreaterThan($initial, count($patches));
    }

    public function testAddPaddingIsExposed(): void
    {
        $patches = $this->simpleDiff->patch_make('The quick brown fox.', 'The quick red fox.');

        self::assertSame(4, mb_strlen($this->simpleDiff->patch_addPadding($patches)));
    }
}
