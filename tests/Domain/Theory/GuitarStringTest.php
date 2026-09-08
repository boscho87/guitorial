<?php

declare(strict_types=1);

namespace App\Tests\Domain\Theory;

use App\Domain\Theory\GuitarString;
use PHPUnit\Framework\TestCase;

final class GuitarStringTest extends TestCase
{
    public function testOpenStringNotes(): void
    {
        self::assertSame('E', GuitarString::get('6')->noteAt(0));
        self::assertSame('A', GuitarString::get('5')->noteAt(0));
        self::assertSame('D', GuitarString::get('4')->noteAt(0));
        self::assertSame('G', GuitarString::get('3')->noteAt(0));
        self::assertSame('B', GuitarString::get('2')->noteAt(0));
        self::assertSame('E', GuitarString::get('1')->noteAt(0));
    }

    public function testFifthFretOfAStringIsD(): void
    {
        self::assertSame('D', GuitarString::get('5')->noteAt(5));
    }

    public function testGStringTwelfthFretIsG(): void
    {
        self::assertSame('G', GuitarString::get('3')->noteAt(12));
    }

    public function testLowAndHighEStringsShareEveryNote(): void
    {
        for ($fret = 0; $fret <= 12; $fret++) {
            self::assertSame(
                GuitarString::get('6')->noteAt($fret),
                GuitarString::get('1')->noteAt($fret),
                "Fret $fret should sound the same on strings 1 and 6",
            );
        }
    }

    public function testLabelIncludesNumberAndLetter(): void
    {
        self::assertSame('6 (E)', GuitarString::get('6')->label());
        self::assertSame('1 (e)', GuitarString::get('1')->label());
    }
}
