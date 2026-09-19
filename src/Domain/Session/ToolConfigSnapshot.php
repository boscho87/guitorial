<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * One fixed, versioned shape per tool - not arbitrary JSON - so a
 * PracticeSession's frozen config_snapshot stays meaningfully readable
 * later (Verlaufsdetail, "Als Übung übernehmen" prefill) without an
 * upgrade pipeline. schemaVersion lets a field be added/renamed later;
 * readers access defensively (isset()) rather than migrating old rows.
 */
final class ToolConfigSnapshot
{
    private const SCHEMA_VERSION = 1;

    /**
     * @param string[] $pairs
     */
    public static function keyRecognition(array $pairs, int $totalQuestions): array
    {
        return ['schemaVersion' => self::SCHEMA_VERSION, 'pairs' => $pairs, 'totalQuestions' => $totalQuestions];
    }

    /**
     * @param string[] $shapes
     * @param string[] $modes
     */
    public static function capoTrainer(array $shapes, array $modes, int $maxFret, int $totalQuestions): array
    {
        return ['schemaVersion' => self::SCHEMA_VERSION, 'shapes' => $shapes, 'modes' => $modes, 'maxFret' => $maxFret, 'totalQuestions' => $totalQuestions];
    }

    /**
     * @param string[] $keys
     * @param string[] $itemTypes
     */
    public static function harmonicFunction(array $keys, array $itemTypes, int $totalQuestions): array
    {
        return ['schemaVersion' => self::SCHEMA_VERSION, 'keys' => $keys, 'itemTypes' => $itemTypes, 'totalQuestions' => $totalQuestions];
    }

    /**
     * @param string[] $strings
     * @param string[] $modes
     */
    public static function fretboard(array $strings, array $modes, int $maxFret, int $totalQuestions): array
    {
        return ['schemaVersion' => self::SCHEMA_VERSION, 'strings' => $strings, 'modes' => $modes, 'maxFret' => $maxFret, 'totalQuestions' => $totalQuestions];
    }

    /**
     * @param string[] $tones
     */
    public static function scaleGenerator(array $tones, int $autoAdvanceSeconds, string $mode = 'tones', ?string $scaleId = null): array
    {
        return ['schemaVersion' => self::SCHEMA_VERSION, 'tones' => $tones, 'autoAdvanceSeconds' => $autoAdvanceSeconds, 'mode' => $mode, 'scaleId' => $scaleId];
    }
}
