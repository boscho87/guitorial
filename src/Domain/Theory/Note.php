<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * Chromatic pitch-class helpers. Notes are spelled with sharps (C, C#, D, ...)
 * since that matches how guitarists read capo/fret positions.
 */
final class Note
{
    private const NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    public static function nameFromIndex(int $chromaticIndex): string
    {
        return self::NAMES[self::normalizeIndex($chromaticIndex)];
    }

    public static function indexFromName(string $name): int
    {
        $index = array_search($name, self::NAMES, true);
        if ($index === false) {
            throw new \InvalidArgumentException(sprintf('Unknown note name "%s".', $name));
        }

        return $index;
    }

    public static function transpose(int $chromaticIndex, int $semitones): int
    {
        return self::normalizeIndex($chromaticIndex + $semitones);
    }

    public static function normalizeIndex(int $chromaticIndex): int
    {
        return (($chromaticIndex % 12) + 12) % 12;
    }
}
