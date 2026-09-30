<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Utils;
use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Corner cases around multi-byte text — the library's headline guarantee is
 * that offsets, lengths and edits are measured in whole characters.
 */
final class UnicodeTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    #[DataProvider('textPairs')]
    public function testDiffReassemblesBothTexts(string $before, string $after): void
    {
        foreach ([true, false] as $checklines) {
            $changes = $this->simpleDiff->diff_main($before, $after, $checklines);

            self::assertSame($before, $this->simpleDiff->diff_text1($changes));
            self::assertSame($after, $this->simpleDiff->diff_text2($changes));
        }
    }

    #[DataProvider('textPairs')]
    public function testDeltaRoundTrips(string $before, string $after): void
    {
        $changes = $this->simpleDiff->diff_main($before, $after);

        self::assertSame(
            $changes,
            $this->simpleDiff->diff_fromDelta($before, $this->simpleDiff->diff_toDelta($changes)),
        );
    }

    #[DataProvider('textPairs')]
    public function testPatchReproducesTheTarget(string $before, string $after): void
    {
        $patches = $this->simpleDiff->patch_make($before, $after);
        [$result, $applied] = $this->simpleDiff->patch_apply($patches, $before);

        self::assertSame($after, $result);
        self::assertNotContains(false, $applied);
    }

    #[DataProvider('textPairs')]
    public function testMatchFindsTheWholePatternInMultiByteText(string $before, string $after): void
    {
        // The pattern is always the first character of the text.
        $firstChar = mb_substr($after, 0, 1);

        self::assertSame(0, $this->simpleDiff->match_main($after, $firstChar, 0));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textPairs(): iterable
    {
        yield 'precomposed accents' => ['café au lait', 'cafè au lait'];
        yield 'combining accent' => ["cafe\u{0301} au lait", 'cafe au lait'];
        yield 'emoji astral plane' => ['good 👍 day', 'good 👎 day'];
        yield 'emoji ZWJ family' => [
            "a\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}b",
            "a\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F466}b",
        ];
        yield 'CJK' => ['你好世界', '你好地球'];
        yield 'right-to-left Arabic' => ['مرحبا', 'مرحبا بك'];
        yield 'Cyrillic' => ['Привет мир', 'Привет всем'];
        yield 'zero width space' => ["a\u{200B}b", 'ab'];
        yield 'arrows' => ['→ move', '← move'];
    }

    public function testCommonPrefixAndSuffixRespectCharacterBoundaries(): void
    {
        // 'é' (C3 A9) and 'è' (C3 A8) share only their first byte.
        self::assertSame(0, $this->simpleDiff->diff_commonPrefix('é', 'è'));
        self::assertSame(0, $this->simpleDiff->diff_commonSuffix('é', 'è'));

        // Emoji share three leading bytes; the boundary must still be snapped back.
        self::assertSame(0, $this->simpleDiff->diff_commonPrefix('👍', '👎'));
        self::assertSame(0, $this->simpleDiff->diff_commonSuffix('👍', '👎'));

        self::assertSame(5, $this->simpleDiff->diff_commonPrefix('café x', 'café y'));
        self::assertSame(1, $this->simpleDiff->diff_commonPrefix('aé', 'aè'));
    }

    public function testMultiByteMiddleInsertion(): void
    {
        self::assertSame(
            [
                [SimpleDiff::DIFF_EQUAL, '你'],
                [SimpleDiff::DIFF_INSERT, '好'],
                [SimpleDiff::DIFF_EQUAL, '世界'],
            ],
            $this->simpleDiff->diff_main('你世界', '你好世界'),
        );
    }

    public function testMatchUsesCharacterOffsets(): void
    {
        $text = '你好，世界！你好，地球！';

        self::assertSame(0, $this->simpleDiff->match_main($text, '你好', 0));
        self::assertSame(6, $this->simpleDiff->match_main($text, '你好', 5));
    }

    public function testEscapeRoundTripsControlAndAstralCharacters(): void
    {
        foreach (["\x00", "\x01\x02\x1f", '👍', "a\n\r\tb", "\u{10FFFF}", ''] as $value) {
            self::assertSame($value, Utils::unescape(Utils::escape($value)));
        }

        self::assertSame('%00', Utils::escape("\x00"));
        self::assertSame('%0A', Utils::escape("\n"));
        self::assertSame("%F0%9F%91%8D", Utils::escape('👍'));
    }
}
