<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * A practice-friendly major or natural-minor key: its scale (correctly
 * spelled, not just chromatic indices) and the diatonic triads built on it.
 *
 * Deliberately limited to the guitar-friendly keys guitarists actually meet
 * in open-chord songs, not the full 15-key circle of fifths.
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
     * @return array<string, self> keyed by id, e.g. "G-Dur", "Am"
     */
    public static function guitarPool(): array
    {
        static $pool = null;

        if ($pool !== null) {
            return $pool;
        }

        $definitions = [
            ['C-Dur', 'C', true, ['C', 'D', 'E', 'F', 'G', 'A', 'B'], 'keine Vorzeichen'],
            ['G-Dur', 'G', true, ['G', 'A', 'B', 'C', 'D', 'E', 'F#'], '1 Kreuz (F#)'],
            ['D-Dur', 'D', true, ['D', 'E', 'F#', 'G', 'A', 'B', 'C#'], '2 Kreuze (F#, C#)'],
            ['A-Dur', 'A', true, ['A', 'B', 'C#', 'D', 'E', 'F#', 'G#'], '3 Kreuze (F#, C#, G#)'],
            ['E-Dur', 'E', true, ['E', 'F#', 'G#', 'A', 'B', 'C#', 'D#'], '4 Kreuze (F#, C#, G#, D#)'],
            ['F-Dur', 'F', true, ['F', 'G', 'A', 'A#', 'C', 'D', 'E'], '1 B (Bb)'],
            ['Am', 'A', false, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], 'keine Vorzeichen'],
            ['Em', 'E', false, ['E', 'F#', 'G', 'A', 'B', 'C', 'D'], '1 Kreuz (F#)'],
            ['Bm', 'B', false, ['B', 'C#', 'D', 'E', 'F#', 'G', 'A'], '2 Kreuze (F#, C#)'],
            ['Dm', 'D', false, ['D', 'E', 'F', 'G', 'A', 'A#', 'C'], '1 B (Bb)'],
            ['F#m', 'F#', false, ['F#', 'G#', 'A', 'B', 'C#', 'D', 'E'], '3 Kreuze (F#, C#, G#)'],
        ];

        $pool = [];
        foreach ($definitions as [$id, $tonic, $isMajor, $scale, $signatureLabel]) {
            $pool[$id] = new self($id, $tonic, $isMajor, $scale, $signatureLabel);
        }

        return $pool;
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
