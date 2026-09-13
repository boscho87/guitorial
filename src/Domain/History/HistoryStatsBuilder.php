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

    /**
     * Deliberately based on monotonic counters (practice days, completed
     * sessions) rather than the resettable current streak - a broken streak
     * must never take an already-earned milestone away again.
     */
    private const DAY_MILESTONES = [3, 7, 14, 30, 60, 100, 200, 365];
    private const SESSION_MILESTONES = [10, 25, 50, 100, 250, 500];

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
     *     longestStreak: int,
     *     totalPracticeDays: int,
     *     totalDurationMinutes: int,
     *     milestones: array{days: array{achieved: ?int, next: ?int}, sessions: array{achieved: ?int, next: ?int}},
     *     series: array<string, array{label: string, points: list<array{date: \DateTimeImmutable, accuracy: float}>}>,
     * }
     */
    public function build(array $sessions): array
    {
        $finished = array_values(array_filter($sessions, static fn (PracticeSession $s): bool => $s->isFinished()));
        $graded = array_values(array_filter($finished, self::isGraded(...)));
        $days = self::practiceDays($finished);
        $totalFinishedRounds = count($finished);

        return [
            'totalFinishedRounds' => $totalFinishedRounds,
            'averageAccuracy' => self::averageAccuracy($graded),
            'currentStreak' => self::currentStreak($days),
            'longestStreak' => self::longestStreak($days),
            'totalPracticeDays' => count($days),
            'totalDurationMinutes' => self::totalDurationMinutes($finished),
            'milestones' => [
                'days' => self::milestoneProgress(count($days), self::DAY_MILESTONES),
                'sessions' => self::milestoneProgress($totalFinishedRounds, self::SESSION_MILESTONES),
            ],
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
     * @param PracticeSession[] $finished
     * @return array<string, true> calendar-day keys (Y-m-d) with at least one finished round
     */
    private static function practiceDays(array $finished): array
    {
        $days = [];
        foreach ($finished as $s) {
            $days[$s->getFinishedAt()->format('Y-m-d')] = true;
        }

        return $days;
    }

    /**
     * Consecutive calendar days with at least one finished round (any
     * tool), walking back from today. Duolingo-style grace: not having
     * practiced yet today doesn't zero out yesterday's streak - it only
     * breaks once a full day has been skipped. Uses the server's default
     * timezone; the app stores no per-visitor timezone, same simplification
     * as the rest of this anonymous-history prototype.
     *
     * @param array<string, true> $days
     */
    private static function currentStreak(array $days): int
    {
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
     * Longest run of consecutive calendar days anywhere in the history, not
     * just the one currently ending at today - a permanent personal best
     * that a broken streak never erases.
     *
     * @param array<string, true> $days
     */
    private static function longestStreak(array $days): int
    {
        if ($days === []) {
            return 0;
        }

        $sorted = array_keys($days);
        sort($sorted);

        $longest = 1;
        $current = 1;
        $previous = new \DateTimeImmutable($sorted[0]);
        for ($i = 1; $i < count($sorted); $i++) {
            $date = new \DateTimeImmutable($sorted[$i]);
            $current = $date->diff($previous)->days === 1 ? $current + 1 : 1;
            $longest = max($longest, $current);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * @param PracticeSession[] $finished
     */
    private static function totalDurationMinutes(array $finished): int
    {
        $seconds = 0;
        foreach ($finished as $s) {
            $seconds += $s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp();
        }

        return (int) round($seconds / 60);
    }

    /**
     * @param int[] $milestones ascending thresholds
     * @return array{achieved: ?int, next: ?int}
     */
    private static function milestoneProgress(int $value, array $milestones): array
    {
        $achieved = null;
        $next = null;
        foreach ($milestones as $milestone) {
            if ($value >= $milestone) {
                $achieved = $milestone;
            } elseif ($next === null) {
                $next = $milestone;
            }
        }

        return ['achieved' => $achieved, 'next' => $next];
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
