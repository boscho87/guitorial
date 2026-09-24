<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;

/**
 * Orders one day's rows by a recommended priority - always only the rows it
 * is handed (Heute passes the active routine's rows, so the order changes
 * within the routine, never across it). Pure logic: it only reads what
 * ExerciseDayViewBuilder already put on each row.
 *
 * Higher score first; equal scores keep the routine's own order (stable), so
 * the recommendation only ever moves things it has a reason to move.
 */
final class ExercisePrioritizer
{
    public const REASON_DIFFICULT = 'zaeh';
    public const REASON_WEEKLY_GOAL = 'wochenziel';
    public const REASON_LONG_AGO = 'lange_nicht';
    public const REASON_NEW = 'neu';

    private const RUNNING = 1000;
    private const DONE_TODAY = -1000;
    private const LEARNING_STATE = [
        PracticeSession::LEARNING_STATE_DIFFICULT => 40,
        PracticeSession::LEARNING_STATE_IMPROVING => 20,
        PracticeSession::LEARNING_STATE_CONFIDENT => 0,
    ];
    private const UNRATED = 20;
    private const DAILY = 15;
    private const WEEKLY_OPEN = 10;
    private const WEEKLY_URGENT = 35;
    private const WEEKLY_MET = -30;
    private const PER_DAY_SINCE_LAST = 2;
    private const MAX_DAYS_COUNTED = 14;
    private const NEVER_PRACTICED = 10;
    private const LONG_AGO_DAYS = 7;

    /**
     * @param list<array<string, mixed>> $rows rows as built by ExerciseDayViewBuilder
     * @return list<array<string, mixed>> the same rows reordered, each with a `priorityReason` (?string)
     */
    public function sort(array $rows, \DateTimeImmutable $today): array
    {
        $scored = [];
        foreach ($rows as $position => $row) {
            [$score, $reason] = $this->score($row, $today);
            $row['priorityReason'] = $reason;
            $scored[] = ['row' => $row, 'score' => $score, 'position' => $position];
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);

        return array_map(static fn (array $entry): array => $entry['row'], $scored);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: int, 1: ?string} score and the single most telling reason
     */
    private function score(array $row, \DateTimeImmutable $today): array
    {
        if (($row['runningSessionId'] ?? null) !== null) {
            return [self::RUNNING, null];
        }
        if ($row['checked'] ?? false) {
            return [self::DONE_TODAY, null];
        }

        $score = 0;
        $reasons = [];

        $learningState = $row['learningState'] ?? null;
        if ($learningState === null) {
            $score += self::UNRATED;
        } else {
            $score += self::LEARNING_STATE[$learningState] ?? 0;
            if ($learningState === PracticeSession::LEARNING_STATE_DIFFICULT) {
                $reasons[self::REASON_DIFFICULT] = 40;
            }
        }

        $rhythm = $row['version']->getRepeatRhythm();
        if ($rhythm === ExerciseVersion::RHYTHM_DAILY) {
            $score += self::DAILY;
        } elseif ($rhythm === ExerciseVersion::RHYTHM_WEEKLY) {
            $target = $row['weeklyTarget'] ?? null;
            $remaining = $target !== null ? $target - ($row['weeklyDone'] ?? 0) : 0;
            $daysLeft = 8 - (int) $today->format('N'); // today through Sunday
            if ($target !== null && $remaining <= 0) {
                $score += self::WEEKLY_MET;
            } elseif ($remaining >= $daysLeft) {
                $score += self::WEEKLY_URGENT;
                $reasons[self::REASON_WEEKLY_GOAL] = 35;
            } else {
                $score += self::WEEKLY_OPEN;
            }
        }

        $lastPracticedOn = $row['lastPracticedOn'] ?? null;
        if ($lastPracticedOn === null) {
            $score += self::NEVER_PRACTICED;
            $reasons[self::REASON_NEW] = self::NEVER_PRACTICED;
        } else {
            $daysSince = max(0, (int) $lastPracticedOn->diff($today->setTime(0, 0))->days);
            $score += self::PER_DAY_SINCE_LAST * min($daysSince, self::MAX_DAYS_COUNTED);
            if ($daysSince >= self::LONG_AGO_DAYS) {
                $reasons[self::REASON_LONG_AGO] = self::PER_DAY_SINCE_LAST * min($daysSince, self::MAX_DAYS_COUNTED);
            }
        }

        arsort($reasons);

        return [$score, $reasons === [] ? null : (string) array_key_first($reasons)];
    }
}
