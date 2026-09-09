<?php

declare(strict_types=1);

namespace App\Tests\Domain\Quiz;

use App\Domain\Quiz\AnswerOptionPicker;
use PHPUnit\Framework\TestCase;

final class AnswerOptionPickerTest extends TestCase
{
    public function testResultAlwaysContainsTheCorrectOption(): void
    {
        $pool = array_map(static fn (int $i): string => (string) $i, range(1, 20));

        for ($i = 0; $i < 20; $i++) {
            $result = AnswerOptionPicker::pick($pool, '7');
            self::assertContains('7', $result);
        }
    }

    public function testCapsAtMaxWhenPoolIsLarger(): void
    {
        $pool = array_map(static fn (int $i): string => (string) $i, range(1, 20));

        $result = AnswerOptionPicker::pick($pool, '1', 10);

        self::assertCount(10, $result);
    }

    public function testDoesNotPadBeyondAvailablePoolSize(): void
    {
        $pool = ['a', 'b', 'c'];

        $result = AnswerOptionPicker::pick($pool, 'a', 10);

        self::assertCount(3, $result);
        self::assertContains('a', $result);
    }

    public function testNeverDuplicatesTheCorrectOption(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e'];

        $result = AnswerOptionPicker::pick($pool, 'a', 10);

        self::assertSame(1, count(array_filter($result, static fn (string $v): bool => $v === 'a')));
    }
}
