<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seeded random testing.
 *
 * Hundreds of generated text pairs are checked against the invariants that must
 * hold for *every* input: a diff rehydrates into both texts, its delta decodes
 * back to the same diff, patches serialise round-trip, and applying a patch
 * reports one result per patch.
 *
 * The seed is fixed, so any failure is reproducible.
 *
 * Note that "applying a patch recreates the target" is deliberately *not*
 * asserted for arbitrary random text: patching is best-effort, and on highly
 * repetitive input the fuzzy matcher may legitimately settle on an equally good
 * but different location. That guarantee is covered instead by
 * {@see testPatchRoundTripForUnambiguousText()}, where the text has no
 * repetition for the matcher to be fooled by.
 */
final class FuzzTest extends TestCase
{
    private const SEED = 20260101;
    private const ITERATIONS = 120;

    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    /**
     * @param list<string> $alphabet
     */
    #[DataProvider('alphabets')]
    public function testStructuralInvariantsHoldForRandomInputs(array $alphabet, string $label): void
    {
        mt_srand(self::SEED);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $before = self::randomText($alphabet, mt_rand(0, 60));
            $after = mt_rand(0, 3) === 0
                ? self::randomText($alphabet, mt_rand(0, 60))
                : self::mutate($before, $alphabet);

            $context = sprintf('%s #%d (%s -> %s)', $label, $iteration, bin2hex($before), bin2hex($after));

            foreach ([true, false] as $checklines) {
                $changes = $this->simpleDiff->diff_main($before, $after, $checklines);
                $flag = 'checklines=' . var_export($checklines, true);

                self::assertSame($before, $this->simpleDiff->diff_text1($changes), "diff_text1, $flag, $context");
                self::assertSame($after, $this->simpleDiff->diff_text2($changes), "diff_text2, $flag, $context");
                self::assertGreaterThanOrEqual(0, $this->simpleDiff->diff_levenshtein($changes), "levenshtein, $flag, $context");
                $this->assertNoEmptyEdits($changes, "diff_main, $flag, $context");

                $delta = $this->simpleDiff->diff_toDelta($changes);
                self::assertSame($changes, $this->simpleDiff->diff_fromDelta($before, $delta), "delta round trip, $flag, $context");

                // The optional clean-up passes must never corrupt the diff.
                $semantic = $changes;
                $this->simpleDiff->diff_cleanupSemantic($semantic);
                self::assertSame($before, $this->simpleDiff->diff_text1($semantic), "cleanupSemantic text1, $flag, $context");
                self::assertSame($after, $this->simpleDiff->diff_text2($semantic), "cleanupSemantic text2, $flag, $context");
                $this->assertNoEmptyEdits($semantic, "cleanupSemantic, $flag, $context");

                $efficient = $changes;
                $this->simpleDiff->diff_cleanupEfficiency($efficient);
                self::assertSame($before, $this->simpleDiff->diff_text1($efficient), "cleanupEfficiency text1, $flag, $context");
                self::assertSame($after, $this->simpleDiff->diff_text2($efficient), "cleanupEfficiency text2, $flag, $context");
                $this->assertNoEmptyEdits($efficient, "cleanupEfficiency, $flag, $context");
            }

            $patches = $this->simpleDiff->patch_make($before, $after);

            // Patch text is stable through serialisation.
            $serialised = $this->simpleDiff->patch_toText($patches);
            self::assertSame(
                $serialised,
                $this->simpleDiff->patch_toText($this->simpleDiff->patch_fromText($serialised)),
                "patch text round trip $context",
            );

            // Applying yields one verdict per patch, or more when a patch is
            // split; it never yields fewer.
            [$result, $applied] = $this->simpleDiff->patch_apply($patches, $before);
            self::assertGreaterThanOrEqual(count($patches), count($applied), "a verdict for every patch $context");

            // When there is nothing to do, the text is returned untouched.
            if ($before === $after) {
                self::assertSame([], $patches, "no patches for identical text $context");
                self::assertSame($after, $result, "identity patch $context");
            }
        }
    }

    /**
     * With every character distinct there is no repeated context, so the matcher
     * cannot be fooled and `patch_apply(patch_make($a, $b), $a)` is exactly `$b`.
     */
    public function testPatchRoundTripForUnambiguousText(): void
    {
        mt_srand(self::SEED + 1);

        // 400 distinct code points: no substring can occur twice.
        $pool = [];
        for ($code = 0x4E00; $code < 0x4E00 + 400; $code++) {
            $pool[] = mb_chr($code, 'UTF-8');
        }
        shuffle($pool);

        for ($iteration = 0; $iteration < 80; $iteration++) {
            $size = mt_rand(5, 80);
            $beforeChars = array_slice($pool, 0, $size);

            // Delete a slice ...
            $afterChars = $beforeChars;
            $cutAt = mt_rand(0, $size - 1);
            $cutLength = mt_rand(1, min(10, $size - $cutAt));
            array_splice($afterChars, $cutAt, $cutLength);

            // ... and insert a slice of fresh, still-unique characters.
            array_splice($afterChars, mt_rand(0, count($afterChars)), 0, array_slice($pool, $size, mt_rand(1, 10)));

            $before = implode('', $beforeChars);
            $after = implode('', $afterChars);

            $patches = $this->simpleDiff->patch_make($before, $after);
            [$result, $applied] = $this->simpleDiff->patch_apply($patches, $before);

            self::assertSame($after, $result, "patch round trip #$iteration");
            self::assertNotContains(false, $applied, "every patch applies #$iteration");
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function alphabets(): iterable
    {
        yield 'ascii' => [str_split('abcde   fg'), 'ascii'];
        yield 'tiny alphabet' => [str_split('ab'), 'binary'];
        yield 'multi-byte' => [['a', 'é', '👍', '世', '☕', "\n", ' '], 'unicode'];
    }

    /**
     * No diff may ever contain an edit with empty text: such records are
     * degenerate and, historically, blocked further merging.
     *
     * @param list<array{0: int, 1: string}> $diffs
     */
    private function assertNoEmptyEdits(array $diffs, string $context): void
    {
        foreach ($diffs as [, $text]) {
            self::assertNotSame('', $text, "no empty edits $context");
        }
    }

    /**
     * @param list<string> $alphabet
     */
    private static function randomText(array $alphabet, int $length): string
    {
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $text;
    }

    /**
     * @param list<string> $alphabet
     */
    private static function mutate(string $text, array $alphabet): string
    {
        $operations = mt_rand(1, 5);
        for ($i = 0; $i < $operations; $i++) {
            $position = $text === '' ? 0 : mt_rand(0, mb_strlen($text));

            switch (mt_rand(0, 2)) {
                case 0: // insert a run
                    $text = mb_substr($text, 0, $position)
                        . self::randomText($alphabet, mt_rand(1, 6))
                        . mb_substr($text, $position);
                    break;
                case 1: // delete a run
                    $text = mb_substr($text, 0, $position)
                        . mb_substr($text, $position + mt_rand(1, 6));
                    break;
                default: // replace one character
                    $text = mb_substr($text, 0, $position)
                        . $alphabet[mt_rand(0, count($alphabet) - 1)]
                        . mb_substr($text, $position);
                    break;
            }
        }

        return $text;
    }
}
