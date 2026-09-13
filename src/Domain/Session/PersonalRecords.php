<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\PracticeSession;
use App\Repository\PracticeSessionRepository;

/**
 * Points-based personal record for one tool: 10 points per correct answer,
 * plus a time bonus on a perfect round (every question correct) that beats
 * the visitor's own prior fastest round with the same question count -
 * bonus = however many seconds faster, capped at 0 (never negative). A
 * points value already fairly balances accuracy and length on its own (a
 * perfect 30/30 outscores a perfect 3/3 without needing a separate
 * tie-break rule), which is why this replaced the earlier two-metric
 * (best-ratio + fastest-same-count-time) design.
 *
 * The bonus deliberately compares against the visitor's own history, not
 * an invented target time per question - there's no universal "correct"
 * pace across tools/visitors, but "faster than your own best" always
 * means something. A perfect round with no prior same-count round to beat
 * gets no bonus (nothing to beat yet), same spirit as the previous
 * "first round never flagged as a new record" rule.
 *
 * Two responsibilities, used at two different points in a round's life:
 * - pointsForFinishedRound()/calculatePoints(): called once, right before
 *   the round is marked finished, to compute the value that gets STORED
 *   on the PracticeSession (PracticeSession.points has no other source of
 *   truth - it's fixed at finish time, not recomputed later, since
 *   "prior fastest at the time" would otherwise silently drift as more
 *   history accumulates).
 * - forTool()/summarize(): called on every render of a finished round's
 *   summary screen, to compare that already-stored points value against
 *   the visitor's other stored results for the same tool.
 */
final class PersonalRecords
{
    private const POINTS_PER_CORRECT = 10;

    public function __construct(private readonly PracticeSessionRepository $sessions)
    {
    }

    /**
     * @return array{points: int, timeBonusPoints: int}
     */
    public function pointsForFinishedRound(string $visitorId, string $tool, PracticeSession $current, int $correctCount, int $totalQuestions, ?int $nowTimestamp = null): array
    {
        return self::calculatePoints($this->sessions->finishedForTool($visitorId, $tool), $current, $correctCount, $totalQuestions, $nowTimestamp ?? time());
    }

    /**
     * @param PracticeSession[] $finishedSessions
     * @return array{points: int, timeBonusPoints: int}
     */
    public static function calculatePoints(array $finishedSessions, PracticeSession $current, int $correctCount, int $totalQuestions, int $nowTimestamp): array
    {
        $base = self::POINTS_PER_CORRECT * $correctCount;
        $isPerfect = $totalQuestions > 0 && $correctCount === $totalQuestions;
        if (!$isPerfect) {
            return ['points' => $base, 'timeBonusPoints' => 0];
        }

        $currentSeconds = $nowTimestamp - $current->getStartedAt()->getTimestamp();

        $priorFastestSeconds = null;
        foreach ($finishedSessions as $session) {
            if ($session === $current) {
                continue;
            }
            if (($session->getTotalQuestions() ?? 0) !== $totalQuestions || $session->getFinishedAt() === null) {
                continue;
            }
            $seconds = $session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp();
            if ($priorFastestSeconds === null || $seconds < $priorFastestSeconds) {
                $priorFastestSeconds = $seconds;
            }
        }

        $bonus = $priorFastestSeconds !== null ? max(0, $priorFastestSeconds - $currentSeconds) : 0;

        return ['points' => $base + $bonus, 'timeBonusPoints' => $bonus];
    }

    /**
     * @return array{points: int, timeBonusPoints: int, bestPoints: int, isNewRecord: bool}|null null if this round was never graded (points was never stored)
     */
    public function forTool(string $visitorId, string $tool, PracticeSession $current): ?array
    {
        return self::summarize($this->sessions->finishedForTool($visitorId, $tool), $current);
    }

    /**
     * @param PracticeSession[] $finishedSessions
     * @return array{points: int, timeBonusPoints: int, bestPoints: int, isNewRecord: bool}|null
     */
    public static function summarize(array $finishedSessions, PracticeSession $current): ?array
    {
        $currentPoints = $current->getPoints();
        if ($currentPoints === null) {
            return null;
        }

        $priorBest = null;
        foreach ($finishedSessions as $session) {
            if ($session === $current) {
                continue;
            }
            $points = $session->getPoints();
            if ($points !== null && ($priorBest === null || $points > $priorBest)) {
                $priorBest = $points;
            }
        }

        $isNewRecord = $priorBest !== null && $currentPoints > $priorBest;

        return [
            'points' => $currentPoints,
            'timeBonusPoints' => $current->getTimeBonusPoints() ?? 0,
            'bestPoints' => $priorBest === null || $currentPoints > $priorBest ? $currentPoints : $priorBest,
            'isNewRecord' => $isNewRecord,
        ];
    }
}
