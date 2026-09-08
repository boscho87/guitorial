<?php

declare(strict_types=1);

namespace App\Domain\Theory;

/**
 * A scale as tonic + type (major, natural/harmonic/melodic minor), spelled
 * with sharps only via Note - unlike MusicKey this doesn't attempt correct
 * diatonic respelling, since harmonic/melodic minor's raised degrees would
 * need double-sharps in some keys. Not worth the complexity for a
 * random-practice tool where the point is the pattern, not the spelling.
 */
final class Scale
{
    /**
     * @param string[] $notes exactly 7 notes, tonic first
     */
    private function __construct(
        public readonly string $id,
        public readonly string $tonic,
        public readonly string $typeId,
        public readonly array $notes,
    ) {
    }

    /** Semitone offsets from the tonic, ascending. */
    private const TYPE_INTERVALS = [
        'major' => [0, 2, 4, 5, 7, 9, 11],
        'natural_minor' => [0, 2, 3, 5, 7, 8, 10],
        'harmonic_minor' => [0, 2, 3, 5, 7, 8, 11],
        'melodic_minor' => [0, 2, 3, 5, 7, 9, 11],
    ];

    private const TYPE_LABELS = [
        'major' => 'Dur',
        'natural_minor' => 'Moll',
        'harmonic_minor' => 'Harmonisch Moll',
        'melodic_minor' => 'Melodisch Moll',
    ];

    /** @return string[] */
    public static function typeIds(): array
    {
        return array_keys(self::TYPE_INTERVALS);
    }

    public static function typeLabel(string $typeId): string
    {
        return self::TYPE_LABELS[$typeId]
            ?? throw new \InvalidArgumentException(sprintf('Unknown scale type "%s".', $typeId));
    }

    /**
     * All 12 tonics x 4 types = 48 scales.
     *
     * @return array<string, self> keyed by id, e.g. "F#:harmonic_minor"
     */
    public static function pool(): array
    {
        static $pool = null;

        if ($pool !== null) {
            return $pool;
        }

        $built = [];
        foreach (range(0, 11) as $tonicIndex) {
            $tonic = Note::nameFromIndex($tonicIndex);
            foreach (self::TYPE_INTERVALS as $typeId => $intervals) {
                $notes = array_map(
                    static fn (int $interval): string => Note::nameFromIndex($tonicIndex + $interval),
                    $intervals,
                );
                $id = $tonic . ':' . $typeId;
                $built[$id] = new self($id, $tonic, $typeId, $notes);
            }
        }

        $pool = $built;

        return $pool;
    }

    public static function get(string $id): self
    {
        $pool = self::pool();
        if (!isset($pool[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown scale "%s".', $id));
        }

        return $pool[$id];
    }

    /** e.g. "F# Melodisch Moll" */
    public function label(): string
    {
        return $this->tonic . ' ' . self::TYPE_LABELS[$this->typeId];
    }
}
