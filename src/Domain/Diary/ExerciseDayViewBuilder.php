<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;
use App\Entity\Routine;

/**
 * Resolves a visitor's ExerciseVersion history + PracticeSession rows into
 * what a given calendar day's plan actually looked like - the ExerciseVersion/
 * Exercise successor of DiaryDayViewBuilder. Unlike the old DiaryItem model
 * there is no separate "check" table: every exercise, auto-tracked or not,
 * is marked done purely by the existence of a finished PracticeSession that
 * day (recompute, never trust a stored flag - the same principle
 * DiaryDayViewBuilder already used for its auto-tracked items).
 */
final class ExerciseDayViewBuilder
{
    private const WEEKDAY_LABELS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    /**
     * @param ExerciseVersion[] $allVersions every version this visitor ever had
     * @param PracticeSession[] $sessions this visitor's recent sessions, any status
     * @return list<array{version: ExerciseVersion, checked: bool, practicedMinutes: ?int, sessionCount: int, runningSessionId: ?int, runningSessionTool: ?string, weeklyDone: ?int, weeklyTarget: ?int}>
     */
    public function buildDay(array $allVersions, array $sessions, \DateTimeImmutable $date): array
    {
        $dailyStats = $this->dailyStatsByExercise($sessions);
        $runningByExercise = $this->runningSessionsByExercise($sessions);

        $rows = [];
        foreach ($allVersions as $version) {
            $exercise = $version->getExercise();
            if (!$version->isActiveOn($date) || !$exercise->isActiveOn($date)) {
                continue;
            }

            $exerciseId = $exercise->getId();
            $dateKey = $date->format('Y-m-d');
            $stat = $dailyStats[$exerciseId][$dateKey] ?? ['minutes' => 0.0, 'count' => 0];
            $targetMinutes = $version->getTargetMinutes();
            $checked = $targetMinutes !== null ? $stat['minutes'] >= $targetMinutes : $stat['count'] >= 1;

            $weeklyDone = null;
            $weeklyTarget = null;
            if ($version->getRepeatRhythm() === ExerciseVersion::RHYTHM_WEEKLY) {
                $weeklyTarget = $version->getRepeatCount();
                $weeklyDone = $this->weeklyDoneCount($dailyStats, $exerciseId, $targetMinutes, $date);
            }

            $runningSession = $runningByExercise[$exerciseId] ?? null;

            $rows[] = [
                'version' => $version,
                'checked' => $checked,
                'practicedMinutes' => $targetMinutes !== null ? (int) round($stat['minutes']) : null,
                'sessionCount' => $stat['count'],
                'runningSessionId' => $runningSession?->getId(),
                'runningSessionTool' => $runningSession?->getTool(),
                'weeklyDone' => $weeklyDone,
                'weeklyTarget' => $weeklyTarget,
            ];
        }

        return $rows;
    }

    /**
     * Same per-day rows as buildDay(), filtered and reordered to one
     * Routine's items instead of every active exercise - used both by
     * Heute (when a routine is marked active) and by a routine's own
     * "starten" view, so the two never diverge.
     *
     * @param ExerciseVersion[] $allVersions
     * @param PracticeSession[] $sessions
     * @return list<array{version: ExerciseVersion, checked: bool, practicedMinutes: ?int, sessionCount: int, runningSessionId: ?int, runningSessionTool: ?string, weeklyDone: ?int, weeklyTarget: ?int}>
     */
    public function buildDayForRoutine(Routine $routine, array $allVersions, array $sessions, \DateTimeImmutable $date): array
    {
        $rowsByExerciseId = [];
        foreach ($this->buildDay($allVersions, $sessions, $date) as $row) {
            $rowsByExerciseId[$row['version']->getExercise()->getId()] = $row;
        }

        $rows = [];
        foreach ($routine->getItems() as $item) {
            $row = $rowsByExerciseId[$item->getExercise()->getId()] ?? null;
            if ($row !== null) {
                // Missing here means the exercise was archived (or its
                // version no longer active) after being added to this
                // routine - nothing to show for it today, skip rather than
                // render a broken row.
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Trailing window as a per-day completion RATIO of only the exercises
     * that were actually "geplant" that day (täglich, or pro_woche whose
     * weekly target wasn't already met) - ohne_vorgabe exercises are
     * opportunistic and never count toward this denominator, a deliberate
     * decision (see the planning doc) distinct from buildDay()'s full
     * display list, which shows every active exercise regardless of rhythm.
     *
     * @param ExerciseVersion[] $allVersions
     * @param PracticeSession[] $sessions
     * @return list<array{date: \DateTimeImmutable, weekdayLabel: string, done: int, total: int}>
     */
    public function buildRecentRatios(array $allVersions, array $sessions, \DateTimeImmutable $today, int $days = 7): array
    {
        $dailyStats = $this->dailyStatsByExercise($sessions);
        $trackingStart = $this->earliestSessionDate($sessions);

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days");
            $dateKey = $date->format('Y-m-d');
            $done = 0;
            $total = 0;

            // A day before the oldest session still on record shows as
            // untracked ("–"), not as a wall of 0/X misses - most visibly
            // after "gesamten Verlauf löschen", which deletes the sessions
            // that proved those days happened but leaves already-active
            // exercises "geplant" for them. Without this, wiping the
            // history would retroactively turn past good days into
            // apparent failures instead of just starting fresh from today.
            foreach ($trackingStart !== null && $date >= $trackingStart ? $allVersions : [] as $version) {
                $exercise = $version->getExercise();
                if (!$version->isActiveOn($date) || !$exercise->isActiveOn($date)) {
                    continue;
                }
                if ($version->getRepeatRhythm() === ExerciseVersion::RHYTHM_NONE) {
                    continue;
                }

                $exerciseId = $exercise->getId();
                $targetMinutes = $version->getTargetMinutes();
                $stat = $dailyStats[$exerciseId][$dateKey] ?? ['minutes' => 0.0, 'count' => 0];
                $checkedToday = $targetMinutes !== null ? $stat['minutes'] >= $targetMinutes : $stat['count'] >= 1;

                if ($version->getRepeatRhythm() === ExerciseVersion::RHYTHM_WEEKLY) {
                    $doneBeforeToday = $this->weeklyDoneCount($dailyStats, $exerciseId, $targetMinutes, $date->modify('-1 day'));
                    if ($doneBeforeToday >= ($version->getRepeatCount() ?? PHP_INT_MAX)) {
                        continue; // weekly goal already met before today - purely opportunistic from here
                    }
                }

                $total++;
                if ($checkedToday) {
                    $done++;
                }
            }

            $result[] = [
                'date' => $date,
                'weekdayLabel' => self::WEEKDAY_LABELS[(int) $date->format('N')],
                'done' => $done,
                'total' => $total,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, array{minutes: float, count: int}>> $dailyStats
     */
    private function weeklyDoneCount(array $dailyStats, int $exerciseId, ?int $targetMinutes, \DateTimeImmutable $throughDate): int
    {
        $dow = (int) $throughDate->format('N');
        $weekStart = $throughDate->modify('-'.($dow - 1).' days');

        $done = 0;
        $cursor = $weekStart;
        while ($cursor <= $throughDate) {
            $stat = $dailyStats[$exerciseId][$cursor->format('Y-m-d')] ?? ['minutes' => 0.0, 'count' => 0];
            $checked = $targetMinutes !== null ? $stat['minutes'] >= $targetMinutes : $stat['count'] >= 1;
            if ($checked) {
                $done++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $done;
    }

    /**
     * The calendar day of the oldest session still on record (any status),
     * or null if there are none at all - e.g. right after a full history
     * wipe, before anything new has been logged yet.
     *
     * @param PracticeSession[] $sessions
     */
    private function earliestSessionDate(array $sessions): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($sessions as $s) {
            $date = $s->getStartedAt()->setTime(0, 0);
            if ($earliest === null || $date < $earliest) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * @param PracticeSession[] $sessions
     * @return array<int, array<string, array{minutes: float, count: int}>> exerciseId => dateKey => stats, finished sessions only
     */
    private function dailyStatsByExercise(array $sessions): array
    {
        $stats = [];
        foreach ($sessions as $s) {
            $exercise = $s->getExercise();
            if ($exercise === null || !$s->isFinished()) {
                continue;
            }

            $exerciseId = $exercise->getId();
            $dateKey = $s->getFinishedAt()->format('Y-m-d');
            $minutes = ($s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp()) / 60;

            $stats[$exerciseId][$dateKey]['minutes'] = ($stats[$exerciseId][$dateKey]['minutes'] ?? 0.0) + $minutes;
            $stats[$exerciseId][$dateKey]['count'] = ($stats[$exerciseId][$dateKey]['count'] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * @param PracticeSession[] $sessions
     * @return array<int, PracticeSession> exerciseId => the running session (last one wins, there should only ever be one)
     */
    private function runningSessionsByExercise(array $sessions): array
    {
        $running = [];
        foreach ($sessions as $s) {
            $exercise = $s->getExercise();
            if ($exercise === null || !$s->isRunning()) {
                continue;
            }
            $running[$exercise->getId()] = $s;
        }

        return $running;
    }
}
