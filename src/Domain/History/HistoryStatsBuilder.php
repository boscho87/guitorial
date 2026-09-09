<?php

declare(strict_types=1);

namespace App\Domain\History;

use App\Domain\Quiz\ToolRegistry;
use App\Entity\PracticeSession;

/**
 * Turns a visitor's raw PracticeSession rows into the aggregates and chart
 * series /verlauf shows - all-time stats plus a capped recent window for the
 * trend line (Monkeytype separates "recent tests" from the all-time average
 * the same way, so a short window and a long-run number can legitimately
 * differ without being a bug).
 */
final class HistoryStatsBuilder
{
    private const CHART_WINDOW = 30;
    private const GRADED_TOOLS = ['tonart-erkennen', 'kapo-trainer', 'funktion-bestimmen', 'griffbrett'];

    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * @param PracticeSession[] $sessions most-recent-first (as returned by
     *   PracticeSessionRepository::forVisitor) - this order is trusted and
     *   never re-sorted by timestamp.
     * @return array{
     *     totalFinishedRounds: int,
     *     averageAccuracy: ?float,
     *     currentStreak: int,
     *     series: array<string, array{label: string, points: list<array{date: \DateTimeImmutable, accuracy: float}>}>,
     * }
     */
    public function build(array $sessions): array
    {
        $finished = array_values(array_filter($sessions, static fn (PracticeSession $s): bool => $s->isFinished()));
        $graded = array_values(array_filter($finished, self::isGraded(...)));

        return [
            'totalFinishedRounds' => count($finished),
            'averageAccuracy' => self::averageAccuracy($graded),
            'currentStreak' => self::currentStreak($finished),
            'series' => $this->buildSeries($graded),
        ];
    }

    /**
     * skalen-generator never sets correctCount (it has no right/wrong
     * answer), so this naturally excludes it without any explicit
     * "graded" flag on the tool itself.
     */
    private static function isGraded(PracticeSession $s): bool
    {
        return $s->getTotalQuestions() !== null
            && $s->getCorrectCount() !== null
            && $s->getTotalQuestions() > 0;
    }

    private static function accuracy(PracticeSession $s): float
    {
        return $s->getCorrectCount() / $s->getTotalQuestions() * 100;
    }

    /**
     * @param PracticeSession[] $graded
     */
    private static function averageAccuracy(array $graded): ?float
    {
        if ($graded === []) {
            return null;
        }

        return array_sum(array_map(self::accuracy(...), $graded)) / count($graded);
    }

    /**
     * Consecutive calendar days with at least one finished round (any
     * tool), walking back from today. Duolingo-style grace: not having
     * practiced yet today doesn't zero out yesterday's streak - it only
     * breaks once a full day has been skipped. Uses the server's default
     * timezone; the app stores no per-visitor timezone, same simplification
     * as the rest of this anonymous-history prototype.
     *
     * @param PracticeSession[] $finished
     */
    private static function currentStreak(array $finished): int
    {
        $days = [];
        foreach ($finished as $s) {
            $days[$s->getFinishedAt()->format('Y-m-d')] = true;
        }

        $cursor = new \DateTimeImmutable('today');
        if (!isset($days[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->modify('-1 day');
            if (!isset($days[$cursor->format('Y-m-d')])) {
                return 0;
            }
        }

        $streak = 0;
        while (isset($days[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }

        return $streak;
    }

    /**
     * @param PracticeSession[] $graded most-recent-first
     * @return array<string, array{label: string, points: list<array{date: \DateTimeImmutable, accuracy: float}>}>
     */
    private function buildSeries(array $graded): array
    {
        $series = [];

        $all = self::pointsFor($graded);
        if (count($all) >= 2) {
            $series['all'] = ['label' => 'history.chart.tab_all', 'points' => $all];
        }

        foreach (self::GRADED_TOOLS as $toolId) {
            $toolSessions = array_values(array_filter(
                $graded,
                static fn (PracticeSession $s): bool => $s->getTool() === $toolId,
            ));
            $points = self::pointsFor($toolSessions);
            if (count($points) >= 2) {
                $series[$toolId] = ['label' => $this->tools->get($toolId)['title'], 'points' => $points];
            }
        }

        return $series;
    }

    /**
     * Caps to the most recent CHART_WINDOW rounds, then reverses to
     * chronological (oldest-first) order for left-to-right rendering.
     *
     * @param PracticeSession[] $sessions most-recent-first
     * @return list<array{date: \DateTimeImmutable, accuracy: float}>
     */
    private static function pointsFor(array $sessions): array
    {
        $capped = array_slice($sessions, 0, self::CHART_WINDOW);

        return array_values(array_map(
            static fn (PracticeSession $s): array => [
                'date' => $s->getFinishedAt(),
                'accuracy' => round(self::accuracy($s), 1),
            ],
            array_reverse($capped),
        ));
    }
}
