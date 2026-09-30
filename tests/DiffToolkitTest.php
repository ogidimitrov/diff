<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\DiffToolkit;
use PHPUnit\Framework\TestCase;

final class DiffToolkitTest extends TestCase
{
    private DiffToolkit $toolkit;

    protected function setUp(): void
    {
        $this->toolkit = new DiffToolkit();
    }

    public function testCommonPrefix(): void
    {
        self::assertSame(0, $this->toolkit->commonPrefix('abc', 'xyz'));
        self::assertSame(3, $this->toolkit->commonPrefix('abcdef', 'abcxyz'));
        self::assertSame(6, $this->toolkit->commonPrefix('abcdef', 'abcdef'));
        self::assertSame(0, $this->toolkit->commonPrefix('', 'abc'));
    }

    public function testCommonPrefixNeverSplitsAMultibyteCharacter(): void
    {
        // 'é' (C3 A9) and 'è' (C3 A8) share their first byte only.
        self::assertSame(0, $this->toolkit->commonPrefix("\u{E9}", "\u{E8}"));
        self::assertSame(1, $this->toolkit->commonPrefix("a\u{E9}", "a\u{E8}"));
    }

    public function testCommonSuffix(): void
    {
        self::assertSame(0, $this->toolkit->commonSuffix('abc', 'xyz'));
        self::assertSame(3, $this->toolkit->commonSuffix('xyzdef', 'abcdef'));
        self::assertSame(6, $this->toolkit->commonSuffix('abcdef', 'abcdef'));
        self::assertSame(0, $this->toolkit->commonSuffix('', 'abc'));
    }

    public function testCommonSuffixNeverSplitsAMultibyteCharacter(): void
    {
        self::assertSame(0, $this->toolkit->commonSuffix("\u{E9}", "\u{E8}"));
        self::assertSame(1, $this->toolkit->commonSuffix("\u{E9}a", "\u{E8}a"));
    }

    public function testCommonOverlap(): void
    {
        self::assertSame(3, $this->toolkit->commonOverlap('abcdef', 'defxyz'));
        self::assertSame(3, $this->toolkit->commonOverlap('xyzabc', 'abc'));
        self::assertSame(0, $this->toolkit->commonOverlap('abc', 'xyz'));
        self::assertSame(0, $this->toolkit->commonOverlap('', 'abc'));
    }

    public function testHalfMatchReturnsNullForUnrelatedTexts(): void
    {
        self::assertNull($this->toolkit->halfMatch('aaaa', 'bbbb'));
    }

    public function testHalfMatchFindsLargeSharedRun(): void
    {
        $shared = 'the quick brown fox ';
        $text1 = $shared . 'jumps';
        $text2 = $shared . 'sleeps';
        $match = $this->toolkit->halfMatch($text1, $text2);

        self::assertNotNull($match);
        self::assertSame($shared, $match[4]);
        // halfMatch always splits both texts around the shared middle.
        self::assertSame($text1, $match[0] . $match[4] . $match[1]);
        self::assertSame($text2, $match[2] . $match[4] . $match[3]);
    }

    public function testLinesToCharsAndBackRoundTrip(): void
    {
        $text1 = "alpha\nbeta\ngamma\n";
        $text2 = "alpha\ndelta\ngamma\n";

        [$chars1, $chars2, $lineArray] = $this->toolkit->linesToChars($text1, $text2);

        // One character per line; the shared table maps them back to text.
        self::assertSame(3, mb_strlen($chars1));
        self::assertSame(3, mb_strlen($chars2));
        self::assertSame('', $lineArray[0], 'element 0 is a placeholder');

        $restored = $this->toolkit->charsToLines(
            [[0, $chars1], [1, $chars2]],
            $lineArray,
        );
        self::assertSame($text1, $restored[0][1]);
        self::assertSame($text2, $restored[1][1]);
    }

    public function testLinesToCharsHandlesTextWithoutTrailingNewline(): void
    {
        [$chars1, , $lineArray] = $this->toolkit->linesToChars('one', 'one');
        $restored = $this->toolkit->charsToLines([[0, $chars1]], $lineArray);

        self::assertSame('one', $restored[0][1]);
    }
}
