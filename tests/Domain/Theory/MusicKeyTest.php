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

    public function testFullPoolHas24Keys(): void
    {
        self::assertCount(24, MusicKey::guitarPool());
    }

    public function testSignaturePairsHas12PairsWithMatchingSignatures(): void
    {
        $pairs = MusicKey::signaturePairs();
        self::assertCount(12, $pairs);

        $cMajorPair = array_values(array_filter($pairs, static fn (array $p) => $p['majorId'] === 'C-Dur'))[0];
        self::assertSame('Am', $cMajorPair['minorId']);
        self::assertSame('C-Dur/A-Moll', $cMajorPair['label']);

        $fSharpPair = array_values(array_filter($pairs, static fn (array $p) => $p['majorId'] === 'F#-Dur'))[0];
        self::assertSame('D#m', $fSharpPair['minorId']);
    }
}
