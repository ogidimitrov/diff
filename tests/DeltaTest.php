<?php

declare(strict_types=1);

namespace OgiDimitrov\Diff\Tests;

use OgiDimitrov\Diff\SimpleDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The compact delta encoding (`=3\t-2\t+ing`) and its inverse.
 */
final class DeltaTest extends TestCase
{
    private SimpleDiff $simpleDiff;

    protected function setUp(): void
    {
        $this->simpleDiff = new SimpleDiff();
    }

    public function testEachOperationEncodesAsExpected(): void
    {
        self::assertSame('', $this->simpleDiff->diff_toDelta([]));
        self::assertSame('=3', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_EQUAL, 'abc']]));
        self::assertSame('-4', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_DELETE, 'abcd']]));
        self::assertSame('+x', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_INSERT, 'x']]));
    }

    public function testOperationsAreTabSeparated(): void
    {
        self::assertSame(
            "=2\t+123",
            $this->simpleDiff->diff_toDelta($this->simpleDiff->diff_main('ab', 'ab123')),
        );

        self::assertSame(
            "=1\t-2\t+ccc\t=4",
            $this->simpleDiff->diff_toDelta([
                [SimpleDiff::DIFF_EQUAL, 'a'],
                [SimpleDiff::DIFF_DELETE, 'bb'],
                [SimpleDiff::DIFF_INSERT, 'ccc'],
                [SimpleDiff::DIFF_EQUAL, 'dddd'],
            ]),
        );
    }

    public function testInsertedTextIsPercentEncoded(): void
    {
        // Newlines and high code points are escaped ...
        self::assertSame('+a%0Ab', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_INSERT, "a\nb"]]));
        self::assertSame("+%F0%9F%91%8D", $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_INSERT, '👍']]));

        // ... while spaces and reserved punctuation are not.
        self::assertSame('+a b', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_INSERT, 'a b']]));
        self::assertSame('+a+b=c', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_INSERT, 'a+b=c']]));
    }

    public function testLengthsAreCountedInCharacters(): void
    {
        self::assertSame('=2', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_EQUAL, '👍👍']]));
        self::assertSame('-3', $this->simpleDiff->diff_toDelta([[SimpleDiff::DIFF_DELETE, '你好世']]));
    }

    public function testDecodingABasicDelta(): void
    {
        self::assertSame(
            [[SimpleDiff::DIFF_DELETE, 'ab'], [SimpleDiff::DIFF_INSERT, 'cd']],
            $this->simpleDiff->diff_fromDelta('ab', "-2\t+cd"),
        );
    }

    public function testEmptyDeltaAndEmptyText(): void
    {
        self::assertSame([], $this->simpleDiff->diff_fromDelta('', ''));
    }

    public function testTrailingTabIsIgnored(): void
    {
        self::assertSame(
            [[SimpleDiff::DIFF_EQUAL, 'abcabc']],
            $this->simpleDiff->diff_fromDelta('abcabc', "=6\t"),
        );
    }

    public function testDecodingCountsCharacters(): void
    {
        self::assertSame(
            [[SimpleDiff::DIFF_EQUAL, '👍👍']],
            $this->simpleDiff->diff_fromDelta('👍👍', '=2'),
        );
    }

    public function testDecodingUndoesEscaping(): void
    {
        self::assertSame(
            [[SimpleDiff::DIFF_INSERT, "a\nb"]],
            $this->simpleDiff->diff_fromDelta('', '+a%0Ab'),
        );
        self::assertSame(
            [[SimpleDiff::DIFF_INSERT, 'a b']],
            $this->simpleDiff->diff_fromDelta('', '+a b'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedDeltas(): iterable
    {
        yield 'non-numeric length' => ['abc', '=abc'];
        yield 'empty length' => ['abc', '='];
        yield 'negative length' => ['abc', '=-1'];
        yield 'unknown operation' => ['abc', '?1'];
        yield 'length past the end' => ['abc', '=99'];
        yield 'delta shorter than the text' => ['abcdef', '=2'];
    }

    #[DataProvider('malformedDeltas')]
    public function testMalformedDeltasAreRejected(string $text, string $delta): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->simpleDiff->diff_fromDelta($text, $delta);
    }
}
