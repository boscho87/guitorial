<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * The three ways a capo question can be asked. The capo only shifts pitch,
 * never major/minor quality, so a shape and its target chord must share
 * quality for a question to be solvable.
 */
final class CapoTransposer
{
    public static function soundingChord(CapoShape $shape, int $capoFret): string
    {
        $index = Note::transpose($shape->rootIndex, $capoFret);

        return Note::nameFromIndex($index) . ($shape->isMinor ? 'm' : '');
    }

    /**
     * Shapes (from $availableShapeIds) that sound as $targetChordName when capoed at $capoFret.
     *
     * @param string[] $availableShapeIds
     * @return CapoShape[]
     */
    public static function shapesReaching(string $targetChordName, int $capoFret, array $availableShapeIds): array
    {
        [$targetRoot, $targetIsMinor] = CapoShape::parseChordName($targetChordName);
        $targetIndex = Note::indexFromName($targetRoot);

        $matches = [];
        foreach ($availableShapeIds as $shapeId) {
            $shape = CapoShape::get($shapeId);
            if ($shape->isMinor !== $targetIsMinor) {
                continue;
            }
            if (Note::transpose($shape->rootIndex, $capoFret) === $targetIndex) {
                $matches[] = $shape;
            }
        }

        return $matches;
    }

    /**
     * The capo fret (within 1..$maxFret) at which $shape sounds as $targetChordName,
     * or null if quality mismatches or no fret in range works.
     */
    public static function fretFor(CapoShape $shape, string $targetChordName, int $maxFret): ?int
    {
        [$targetRoot, $targetIsMinor] = CapoShape::parseChordName($targetChordName);
        if ($shape->isMinor !== $targetIsMinor) {
            return null;
        }

        $targetIndex = Note::indexFromName($targetRoot);
        $fret = Note::normalizeIndex($targetIndex - $shape->rootIndex);

        return ($fret >= 1 && $fret <= $maxFret) ? $fret : null;
    }
}
