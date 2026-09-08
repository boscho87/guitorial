<?php

declare(strict_types=1);

namespace App\Tests\Domain\Theory;

use App\Domain\Theory\CapoShape;
use App\Domain\Theory\CapoTransposer;
use PHPUnit\Framework\TestCase;

final class CapoTransposerTest extends TestCase
{
    public function testShapeGWithCapo2SoundsAsA(): void
    {
        self::assertSame('A', CapoTransposer::soundingChord(CapoShape::get('G'), 2));
    }

    public function testShapeDWithCapo3SoundsAsF(): void
    {
        self::assertSame('F', CapoTransposer::soundingChord(CapoShape::get('D'), 3));
    }

    public function testMinorShapeStaysMinorWhenTransposed(): void
    {
        self::assertSame('Bm', CapoTransposer::soundingChord(CapoShape::get('Am'), 2));
    }

    public function testShapesReachingFindsGShapeForTargetAAtCapo2(): void
    {
        $shapes = CapoTransposer::shapesReaching('A', 2, ['C', 'A', 'G', 'E', 'D']);

        self::assertCount(1, $shapes);
        self::assertSame('G', $shapes[0]->id);
    }

    public function testShapesReachingIgnoresQualityMismatch(): void
    {
        // Shape G (major) can never sound as a minor chord, whatever the capo fret.
        $shapes = CapoTransposer::shapesReaching('Am', 2, ['G']);

        self::assertSame([], $shapes);
    }

    public function testFretForShapeDToTargetEIsTwo(): void
    {
        self::assertSame(2, CapoTransposer::fretFor(CapoShape::get('D'), 'E', 7));
    }

    public function testFretForReturnsNullWhenOutOfRange(): void
    {
        // D -> C# would need fret 11, out of a realistic 1..7 capo range.
        self::assertNull(CapoTransposer::fretFor(CapoShape::get('D'), 'C#', 7));
    }

    public function testFretForReturnsNullOnQualityMismatch(): void
    {
        self::assertNull(CapoTransposer::fretFor(CapoShape::get('D'), 'Em', 7));
    }
}
