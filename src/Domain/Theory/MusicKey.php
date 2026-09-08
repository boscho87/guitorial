<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * A major or natural-minor key: its scale (correctly spelled, not just
 * chromatic indices) and the diatonic triads built on it.
 *
 * Covers all 12 pitch classes as major keys (one spelling each - F# rather
 * than Gb, Db rather than C#, to match common guitar/practice notation) plus
 * their relative natural minors, derived by rotating the major scale rather
 * than hand-spelling each minor separately.
 */
final class MusicKey
{
    /**
     * @param string[] $scale exactly 7 correctly spelled scale note names, tonic first
     */
    private function __construct(
        public readonly string $id,
        public readonly string $tonic,
        public readonly bool $isMajor,
        public readonly array $scale,
        public readonly string $signatureLabel,
    ) {
    }

    private const MAJOR_QUALITIES = ['maj', 'min', 'min', 'maj', 'maj', 'min', 'dim'];
    private const MAJOR_ROMANS = ['I', 'ii', 'iii', 'IV', 'V', 'vi', 'vii°'];

    private const MINOR_QUALITIES = ['min', 'dim', 'maj', 'min', 'min', 'maj', 'maj'];
    private const MINOR_ROMANS = ['i', 'ii°', 'III', 'iv', 'v', 'VI', 'VII'];

    /**
     * Tonic, scale (sharp side first, then flat side) and signature label
     * for all 12 major keys. Relative minors are derived below by rotating
     * each major scale to start on its 6th degree - same notes, same
     * signature, no separate hand-spelled table needed.
     */
    private const MAJOR_DEFS = [
        ['C-Dur', 'C', ['C', 'D', 'E', 'F', 'G', 'A', 'B'], 'keine Vorzeichen'],
        ['G-Dur', 'G', ['G', 'A', 'B', 'C', 'D', 'E', 'F#'], '1 Kreuz (F#)'],
        ['D-Dur', 'D', ['D', 'E', 'F#', 'G', 'A', 'B', 'C#'], '2 Kreuze (F#, C#)'],
        ['A-Dur', 'A', ['A', 'B', 'C#', 'D', 'E', 'F#', 'G#'], '3 Kreuze (F#, C#, G#)'],
        ['E-Dur', 'E', ['E', 'F#', 'G#', 'A', 'B', 'C#', 'D#'], '4 Kreuze (F#, C#, G#, D#)'],
        ['B-Dur', 'B', ['B', 'C#', 'D#', 'E', 'F#', 'G#', 'A#'], '5 Kreuze (F#, C#, G#, D#, A#)'],
        ['F#-Dur', 'F#', ['F#', 'G#', 'A#', 'B', 'C#', 'D#', 'E#'], '6 Kreuze (F#, C#, G#, D#, A#, E#)'],
        ['F-Dur', 'F', ['F', 'G', 'A', 'Bb', 'C', 'D', 'E'], '1 B (Bb)'],
        ['Bb-Dur', 'Bb', ['Bb', 'C', 'D', 'Eb', 'F', 'G', 'A'], '2 B (Bb, Eb)'],
        ['Eb-Dur', 'Eb', ['Eb', 'F', 'G', 'Ab', 'Bb', 'C', 'D'], '3 B (Bb, Eb, Ab)'],
        ['Ab-Dur', 'Ab', ['Ab', 'Bb', 'C', 'Db', 'Eb', 'F', 'G'], '4 B (Bb, Eb, Ab, Db)'],
        ['Db-Dur', 'Db', ['Db', 'Eb', 'F', 'Gb', 'Ab', 'Bb', 'C'], '5 B (Bb, Eb, Ab, Db, Gb)'],
    ];

    /**
     * @return array<string, self> keyed by id, e.g. "G-Dur", "Am"
     */
    public static function guitarPool(): array
    {
        static $pool = null;

        if ($pool !== null) {
            return $pool;
        }

        $pool = [];
        foreach (self::MAJOR_DEFS as [$id, $tonic, $scale, $signatureLabel]) {
            $pool[$id] = new self($id, $tonic, true, $scale, $signatureLabel);

            $minorScale = array_merge(array_slice($scale, 5), array_slice($scale, 0, 5));
            $minorTonic = $minorScale[0];
            $minorId = $minorTonic . 'm';
            $pool[$minorId] = new self($minorId, $minorTonic, false, $minorScale, $signatureLabel);
        }

        return $pool;
    }

    /**
     * The 12 relative major/minor pairs (same notes, same key signature -
     * e.g. C-Dur/Am) as one signature group per entry, for UIs that don't
     * want to force a choice between a major and its relative minor.
     *
     * @return list<array{majorId: string, minorId: string, label: string, signatureLabel: string}>
     */
    public static function signaturePairs(): array
    {
        static $pairs = null;

        if ($pairs !== null) {
            return $pairs;
        }

        $pool = self::guitarPool();
        $pairs = [];
        foreach (self::MAJOR_DEFS as [$majorId, , , $signatureLabel]) {
            foreach ($pool as $key) {
                if (!$key->isMajor && $key->signatureLabel === $signatureLabel) {
                    $pairs[] = [
                        'majorId' => $majorId,
                        'minorId' => $key->id,
                        'label' => $majorId . '/' . $key->tonic . '-Moll',
                        'signatureLabel' => $signatureLabel,
                    ];
                    break;
                }
            }
        }

        return $pairs;
    }

    public static function get(string $id): self
    {
        $pool = self::guitarPool();
        if (!isset($pool[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown key "%s".', $id));
        }

        return $pool[$id];
    }

    /**
     * @return list<array{degree: int, roman: string, note: string, quality: string, name: string}>
     */
    public function diatonicChords(): array
    {
        $qualities = $this->isMajor ? self::MAJOR_QUALITIES : self::MINOR_QUALITIES;
        $romans = $this->isMajor ? self::MAJOR_ROMANS : self::MINOR_ROMANS;

        $chords = [];
        foreach ($this->scale as $degreeIndex => $note) {
            $quality = $qualities[$degreeIndex];
            $chords[] = [
                'degree' => $degreeIndex + 1,
                'roman' => $romans[$degreeIndex],
                'note' => $note,
                'quality' => $quality,
                'name' => self::chordName($note, $quality),
            ];
        }

        return $chords;
    }

    /** e.g. "G, Am, Bm, C, D, Em, F#°" */
    public function chordSummary(): string
    {
        return implode(', ', array_column($this->diatonicChords(), 'name'));
    }

    private static function chordName(string $note, string $quality): string
    {
        return match ($quality) {
            'maj' => $note,
            'min' => $note . 'm',
            'dim' => $note . '°',
            default => throw new \LogicException('Unreachable'),
        };
    }
}
