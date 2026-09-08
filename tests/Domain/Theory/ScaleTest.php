<?php

declare(strict_types=1);

namespace App\Tests\Domain\Theory;

use App\Domain\Theory\Scale;
use PHPUnit\Framework\TestCase;

final class ScaleTest extends TestCase
{
    public function testCMajor(): void
    {
        $scale = Scale::get('C:major');

        self::assertSame(['C', 'D', 'E', 'F', 'G', 'A', 'B'], $scale->notes);
        self::assertSame('C Dur', $scale->label());
    }

    public function testANaturalMinor(): void
    {
        $scale = Scale::get('A:natural_minor');

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], $scale->notes);
    }

    public function testAHarmonicMinorRaisesSeventh(): void
    {
        $scale = Scale::get('A:harmonic_minor');

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G#'], $scale->notes);
    }

    public function testAMelodicMinorRaisesSixthAndSeventh(): void
    {
        $scale = Scale::get('A:melodic_minor');

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F#', 'G#'], $scale->notes);
    }

    public function testPoolHasFortyEightScales(): void
    {
        self::assertCount(48, Scale::pool());
    }

    public function testUnknownScaleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Scale::get('H:major');
    }
}
