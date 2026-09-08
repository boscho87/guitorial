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

    /**
     * F#-Dur is the trickiest of the 12 majors to spell correctly: its
     * 7th degree must be E# (not F), or the vii° chord and the relative
     * minor (D#m, not Ebm) would come out wrong.
     */
    public function testFSharpMajorUsesEDoubleSharpSpellingNotF(): void
    {
        $key = MusicKey::get('F#-Dur');

        self::assertSame(['F#', 'G#', 'A#', 'B', 'C#', 'D#', 'E#'], $key->scale);
        self::assertSame('F#, G#m, A#m, B, C#, D#m, E#°', $key->chordSummary());
    }

    public function testDSharpMinorSharesFSharpMajorsChordsAndSignature(): void
    {
        $key = MusicKey::get('D#m');

        self::assertSame('6 Kreuze (F#, C#, G#, D#, A#, E#)', $key->signatureLabel);
        self::assertSame('D#m, E#°, F#, G#m, A#m, B, C#', $key->chordSummary());
    }

    public function testDbMajorRelativeMinorIsBbMinor(): void
    {
        $key = MusicKey::get('Db-Dur');

        self::assertSame(['Db', 'Eb', 'F', 'Gb', 'Ab', 'Bb', 'C'], $key->scale);
        self::assertSame('Bbm', array_values(array_filter(
            MusicKey::signaturePairs(),
            static fn (array $p) => $p['majorId'] === 'Db-Dur',
        ))[0]['minorId']);
    }

    public function testEveryMajorKeyHasSevenUniqueLetterNames(): void
    {
        foreach (MusicKey::guitarPool() as $key) {
            if (!$key->isMajor) {
                continue;
            }
            $letters = array_map(static fn (string $note) => $note[0], $key->scale);
            self::assertCount(7, array_unique($letters), "Key {$key->id} repeats a letter name: " . implode(' ', $key->scale));
        }
    }
}
