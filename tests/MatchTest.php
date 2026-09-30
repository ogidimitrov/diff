<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\SimpleDiff;
use OgiDimitrov\Diff\Matcher;
use PHPUnit\Framework\TestCase;

final class MatchTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    public function testReadmeExamples(): void
    {
        $text = 'The quick brown fox jumps over the lazy fox.';

        self::assertSame(16, $this->simpleDiff->match_main($text, 'fox', 0));
        self::assertSame(40, $this->simpleDiff->match_main($text, 'fox', 40));
        self::assertSame(20, $this->simpleDiff->match_main($text, 'jmps'));
        self::assertSame(-1, $this->simpleDiff->match_main($text, 'jmped'));

        $this->simpleDiff->Match_Threshold = 0.7;
        self::assertSame(20, $this->simpleDiff->match_main($text, 'jmped'));
    }

    public function testExactMatchReturnsTheRequestedLocation(): void
    {
        self::assertSame(4, $this->simpleDiff->match_main('abcdef', 'ef', 4));
    }

    public function testEmptyTextHasNoMatch(): void
    {
        self::assertSame(-1, $this->simpleDiff->match_main('', 'abc'));
    }

    public function testLocationIsClampedIntoRange(): void
    {
        // `loc` beyond the end is clamped to the text length before matching.
        self::assertSame(5, $this->simpleDiff->match_main('abcdef', 'f', 999));
    }

    public function testMultibyteTextIsMatchedByCharacter(): void
    {
        $text = 'café au lait ☕';

        self::assertSame(0, $this->simpleDiff->match_main($text, 'café', 0));
        self::assertSame(8, $this->simpleDiff->match_main($text, 'lait', 5));
    }

    public function testPatternLongerThanMaxBitsIsRejected(): void
    {
        $this->simpleDiff->Match_MaxBits = 4;

        $this->expectException(\RangeException::class);

        $this->simpleDiff->match_main('some text here', 'pattern', 0);
    }

    public function testNullArgumentsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->match_main('abc', null);
    }

    public function testAlphabetBuildsPositionMasks(): void
    {
        $matcher = new Matcher();

        self::assertSame(['a' => 0b10, 'b' => 0b01], $matcher->alphabet('ab'));
    }

    public function testMaxBitsCannotExceedIntegerWidth(): void
    {
        $matcher = new Matcher();

        $this->expectException(\RangeException::class);

        $matcher->setMaxBits(PHP_INT_SIZE * 8 + 1);
    }

    public function testZeroDistanceAnchorsMatchesToTheRequestedLocation(): void
    {
        // By default the nearest occurrence wins.
        self::assertSame(0, $this->simpleDiff->match_main('abcabc', 'abc', 1));

        // With no distance tolerance, a perfect location scores 1.0, so a match
        // is only accepted when it sits exactly at `loc`.
        $this->simpleDiff->Match_Distance = 0;

        self::assertSame(1, $this->simpleDiff->match_main('abcabc', 'abc', 1));
    }

    public function testPatternLongerThanTheTextIsScoredAgainstTheThreshold(): void
    {
        // A loose threshold still accepts a heavily erring match ...
        self::assertSame(0, $this->simpleDiff->match_main('abc', 'abcdef'));

        // ... while a strict one rejects it.
        $this->simpleDiff->Match_Threshold = 0.2;

        self::assertSame(-1, $this->simpleDiff->match_main('abc', 'abcdef'));
    }

    public function testNegativeLocationIsClampedToZero(): void
    {
        self::assertSame(0, $this->simpleDiff->match_main('abc', 'abc', -5));
        self::assertSame(0, $this->simpleDiff->match_main('abcdef', 'a', -100));
    }

    public function testEmptyPatternMatchesAtTheLocation(): void
    {
        self::assertSame(1, $this->simpleDiff->match_main('abc', '', 1));
    }

    public function testIdenticalEmptyTextsMatchAtZero(): void
    {
        self::assertSame(0, $this->simpleDiff->match_main('', ''));
    }

    public function testThePatternIsTreatedLiterally(): void
    {
        // A dot is a dot, not "any character".
        self::assertSame(1, $this->simpleDiff->match_main('a.c', '.'));
        self::assertSame(0, $this->simpleDiff->match_main('a.c', 'a.'));
    }

    public function testZeroMaxBitsDisablesTheLengthLimit(): void
    {
        $this->simpleDiff->Match_MaxBits = 0;

        self::assertSame(10, $this->simpleDiff->match_main('the quick brown fox', 'brown'));
    }
}
