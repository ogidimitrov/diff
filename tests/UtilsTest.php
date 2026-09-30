<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\Utils;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testLengthCountsCodePointsNotBytes(): void
    {
        self::assertSame(0, Utils::length(''));
        self::assertSame(3, Utils::length('abc'));
        // 'héllo' is 6 bytes but 5 characters.
        self::assertSame(6, strlen('héllo'));
        self::assertSame(5, Utils::length('héllo'));
        self::assertSame(1, Utils::length('👍'));
        self::assertSame(2, Utils::length('👍👍'));
    }

    public function testSubstringUsesCodePointOffsets(): void
    {
        self::assertSame('llo', Utils::substring('héllo', 2));
        self::assertSame('é', Utils::substring('héllo', 1, 1));
        self::assertSame('👍c', Utils::substring('a👍c', 1, 2));
        self::assertSame('é', Utils::substring('héllo', -4, 1));
        self::assertSame('hél', Utils::substring('héllo', 0, -2));
    }

    public function testCharAtReturnsWholeCharacters(): void
    {
        self::assertSame('é', Utils::charAt('héllo', 1));
        self::assertSame('👍', Utils::charAt('a👍', 1));
        self::assertSame('', Utils::charAt('abc', 9));
    }

    public function testPositionUsesCodePointOffsets(): void
    {
        self::assertSame(1, Utils::position('héllo', 'é'));
        self::assertSame(2, Utils::position('a👍b', 'b'));
        self::assertFalse(Utils::position('abc', 'z'));
    }

    public function testSplitReturnsIndividualCharacters(): void
    {
        self::assertSame([], Utils::split(''));
        self::assertSame(['a', '👍', 'é'], Utils::split('a👍é'));
    }

    public function testCharAndCodeRoundTrip(): void
    {
        foreach ([65, 0xE9, 0x2615, 0x1F44D] as $code) {
            self::assertSame($code, Utils::codeFromChar(Utils::charFromCode($code)));
        }
    }

    public function testEscapeLeavesSafeCharactersAndEscapesControlCharacters(): void
    {
        self::assertSame('a b!?&=', Utils::escape('a b!?&='));
        self::assertSame('line%0Abreak', Utils::escape("line\nbreak"));
        self::assertSame('%25', Utils::escape('%'));
    }

    /**
     * @dataProvider escapedStrings
     */
    public function testEscapeAndUnescapeAreInverse(string $value): void
    {
        self::assertSame($value, Utils::unescape(Utils::escape($value)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapedStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'plain' => ['The quick brown fox'];
        yield 'control' => ["a\tb\nc\r\nd"];
        yield 'percent literal' => ['100% sure? #tag'];
        yield 'safe punctuation' => ["!~*'();/?:@&=+$,# "];
        yield 'multibyte' => ['café ☕ 🙂'];
        yield 'reserved' => ['a+b=c&d'];
    }
}
