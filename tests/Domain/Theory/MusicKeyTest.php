<?php

declare(strict_types=1);

namespace App\Tests\Domain\Theory;

use App\Domain\Theory\MusicKey;
use PHPUnit\Framework\TestCase;

final class MusicKeyTest extends TestCase
{
    public function testGMajorDiatonicChords(): void
    {
        $key = MusicKey::get('G-Dur');

        self::assertSame('1 Kreuz (F#)', $key->signatureLabel);
        self::assertSame('G, Am, Bm, C, D, Em, F#°', $key->chordSummary());
    }

    public function testAMinorDiatonicChords(): void
    {
        $key = MusicKey::get('Am');

        self::assertSame('keine Vorzeichen', $key->signatureLabel);
        self::assertSame('Am, B°, C, Dm, Em, F, G', $key->chordSummary());
    }

    public function testDMajorChordQualities(): void
    {
        $chords = MusicKey::get('D-Dur')->diatonicChords();

        self::assertSame(
            ['maj', 'min', 'min', 'maj', 'maj', 'min', 'dim'],
            array_column($chords, 'quality'),
        );
        self::assertSame(
            ['I', 'ii', 'iii', 'IV', 'V', 'vi', 'vii°'],
            array_column($chords, 'roman'),
        );
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MusicKey::get('H-Dur');
    }
}
