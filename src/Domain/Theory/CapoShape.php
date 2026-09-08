<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * One of the open chord shapes guitarists actually use with a capo.
 */
final class CapoShape
{
    private function __construct(
        public readonly string $id,
        public readonly int $rootIndex,
        public readonly bool $isMinor,
    ) {
    }

    /**
     * @return array<string, self> keyed by shape id, e.g. "G", "Am"
     */
    public static function all(): array
    {
        static $shapes = null;

        if ($shapes !== null) {
            return $shapes;
        }

        $majorRoots = ['C', 'A', 'G', 'E', 'D'];
        $minorRoots = ['A', 'E', 'D'];

        $shapes = [];
        foreach ($majorRoots as $root) {
            $shapes[$root] = new self($root, Note::indexFromName($root), false);
        }
        foreach ($minorRoots as $root) {
            $id = $root . 'm';
            $shapes[$id] = new self($id, Note::indexFromName($root), true);
        }

        return $shapes;
    }

    public static function get(string $id): self
    {
        $shapes = self::all();
        if (!isset($shapes[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown capo shape "%s".', $id));
        }

        return $shapes[$id];
    }

    /** e.g. "Am" -> root "A", minor */
    public static function parseChordName(string $chordName): array
    {
        if (str_ends_with($chordName, 'm')) {
            return [substr($chordName, 0, -1), true];
        }

        return [$chordName, false];
    }
}
