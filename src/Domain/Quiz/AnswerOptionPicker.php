<?php

declare(strict_types=1);

namespace App\Domain\Quiz;

/**
 * Caps a question's answer choices at a fixed maximum so every button can
 * always carry a 1-9/0 keyboard shortcut (see the `keycap` spans in the
 * practice templates) - some tools' full candidate pool (e.g. all 12
 * chromatic notes, or a wide fret range) would otherwise exceed that.
 */
final class AnswerOptionPicker
{
    /**
     * @param list<string> $pool every candidate option key, must contain $correct
     * @return list<string> at most $max keys, always includes $correct, shuffled
     */
    public static function pick(array $pool, string $correct, int $max = 10): array
    {
        $others = array_values(array_diff($pool, [$correct]));
        shuffle($others);
        $selected = array_slice($others, 0, max(0, $max - 1));
        $selected[] = $correct;
        shuffle($selected);

        return $selected;
    }
}
