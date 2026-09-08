<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * One of the 6 strings in standard tuning (E A D G B e). Numbered the way
 * guitarists talk about them: 6 = low E (thickest), 1 = high e (thinnest).
 * Strings 1 and 6 share the same open note, so any note-at-a-fret they
 * produce is identical - callers that grade "which string" must check
 * noteAt() equality, not string-number equality, or a correct answer on
 * either one would be marked wrong.
 */
final class GuitarString
{
    private function __construct(
        public readonly int $number,
        public readonly string $label,
        public readonly int $openNoteIndex,
    ) {
    }

    /**
     * @return array<string, self> keyed by string number as a string, ordered 6 (low) to 1 (high)
     */
    public static function all(): array
    {
        static $strings = null;

        if ($strings !== null) {
            return $strings;
        }

        // label is the display letter (lowercase "e" for the high string,
        // by guitar convention); noteName is what Note::indexFromName()
        // understands (uppercase only) - they differ only for string 1.
        $defs = [
            [6, 'E', 'E'],
            [5, 'A', 'A'],
            [4, 'D', 'D'],
            [3, 'G', 'G'],
            [2, 'B', 'B'],
            [1, 'e', 'E'],
        ];

        $built = [];
        foreach ($defs as [$number, $label, $noteName]) {
            $built[(string) $number] = new self($number, $label, Note::indexFromName($noteName));
        }
        $strings = $built;

        return $strings;
    }

    public static function get(string $number): self
    {
        $strings = self::all();
        if (!isset($strings[$number])) {
            throw new \InvalidArgumentException(sprintf('Unknown string "%s".', $number));
        }

        return $strings[$number];
    }

    public function noteAt(int $fret): string
    {
        return Note::nameFromIndex(Note::transpose($this->openNoteIndex, $fret));
    }

    public function label(): string
    {
        return $this->number . ' (' . $this->label . ')';
    }
}
