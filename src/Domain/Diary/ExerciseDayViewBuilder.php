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
     * @return list<array{version: ExerciseVersion, checked: bool, practicedMinutes: ?int, sessionCount: int, runningSessionId: ?int, runningSessionTool: ?string, weeklyDone: ?int, weeklyTarget: ?int, learningState: ?string, lastPracticedOn: ?DateTimeImmutable}>
     */
    public function buildDay(array $allVersions, array $sessions, \DateTimeImmutable $date): array
    {
        $dailyStats = $this->dailyStatsByExercise($sessions);
        $runningByExercise = $this->runningSessionsByExercise($sessions);
        $latestByExercise = $this->latestFinishedByExercise($sessions);

        $rows = [];
        foreach ($allVersions as $version) {
            $exercise = $version->getExercise();
            if (!$version->isActiveOn($date) || !$exercise->isActiveOn($date)) {
                continue;
            }

            $exerciseId = $exercise->getId();
            $dateKey = $date->format('Y-m-d');
            $stat = $dailyStats[$exerciseId][$dateKey] ?? ['minutes' => 0.0, 'count' => 0, 'completedMinutes' => 0.0, 'completedCount' => 0];
            $targetMinutes = $version->getTargetMinutes();
            // Driven by completed-only stats, not the creditable total just
            // below - "zäh"/"wird besser" time shows in the progress bar
            // but never checks the exercise off by itself.
            $checked = $targetMinutes !== null ? $stat['completedMinutes'] >= $targetMinutes : $stat['completedCount'] >= 1;

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
                'learningState' => $latestByExercise[$exerciseId]['learningState'] ?? null,
                'lastPracticedOn' => $latestByExercise[$exerciseId]['lastPracticedOn'] ?? null,
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
     * @return list<array{version: ExerciseVersion, checked: bool, practicedMinutes: ?int, sessionCount: int, runningSessionId: ?int, runningSessionTool: ?string, weeklyDone: ?int, weeklyTarget: ?int, learningState: ?string, lastPracticedOn: ?DateTimeImmutable}>
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
     * Trailing window of total practiced minutes per day, across every
     * creditable session regardless of exercise rhythm/target or which
     * routine (if any) the exercise belonged to. Replaces an earlier
     * done/total ratio that only counted täglich/pro_woche exercises toward
     * its denominator - ohne_vorgabe exercises (e.g. a bundled "Songs Band"
     * exercise practiced opportunistically) never showed up there at all,
     * which read as "only today's routine counts" even though the real
     * cause was the rhythm filter, not routine membership.
     *
     * @param PracticeSession[] $sessions
     * @return list<array{date: \DateTimeImmutable, weekdayLabel: string, minutes: int}>
     */
    public function buildRecentPracticeTime(array $sessions, \DateTimeImmutable $today, int $days = 7): array
    {
        $minutesByDay = [];
        foreach ($sessions as $s) {
            if (!$s->isCreditable()) {
                continue;
            }
            $dateKey = $s->getFinishedAt()->format('Y-m-d');
            $minutesByDay[$dateKey] = ($minutesByDay[$dateKey] ?? 0.0)
                + ($s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp()) / 60;
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days");
            $result[] = [
                'date' => $date,
                'weekdayLabel' => self::WEEKDAY_LABELS[(int) $date->format('N')],
                'minutes' => (int) round($minutesByDay[$date->format('Y-m-d')] ?? 0.0),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, array{minutes: float, count: int, completedMinutes: float, completedCount: int}>> $dailyStats
     */
    private function weeklyDoneCount(array $dailyStats, int $exerciseId, ?int $targetMinutes, \DateTimeImmutable $throughDate): int
    {
        $dow = (int) $throughDate->format('N');
        $weekStart = $throughDate->modify('-'.($dow - 1).' days');

        $done = 0;
        $cursor = $weekStart;
        while ($cursor <= $throughDate) {
            $stat = $dailyStats[$exerciseId][$cursor->format('Y-m-d')] ?? ['minutes' => 0.0, 'count' => 0, 'completedMinutes' => 0.0, 'completedCount' => 0];
            $checked = $targetMinutes !== null ? $stat['completedMinutes'] >= $targetMinutes : $stat['completedCount'] >= 1;
            if ($checked) {
                $done++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $done;
    }

    /**
     * Two parallel tallies per exercise per day: `minutes`/`count` (every
     * creditable session - STATUS_FINISHED or STATUS_STARTED, i.e. "zäh"/
     * "wird besser" too) drive the displayed practicedMinutes/sessionCount,
     * while `completedMinutes`/`completedCount` (STATUS_FINISHED only)
     * drive the Heute checkmark/weekly-target logic - practicing counts
     * for the time shown either way, but only a genuine "sicher" (or an
     * unrated completion) checks the exercise off.
     *
     * @param PracticeSession[] $sessions
     * @return array<int, array<string, array{minutes: float, count: int, completedMinutes: float, completedCount: int}>>
     */
    private function dailyStatsByExercise(array $sessions): array
    {
        $stats = [];
        foreach ($sessions as $s) {
            $exercise = $s->getExercise();
            if ($exercise === null || !$s->isCreditable()) {
                continue;
            }

            $exerciseId = $exercise->getId();
            $dateKey = $s->getFinishedAt()->format('Y-m-d');
            $minutes = ($s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp()) / 60;
            $stats[$exerciseId][$dateKey] ??= ['minutes' => 0.0, 'count' => 0, 'completedMinutes' => 0.0, 'completedCount' => 0];

            $stats[$exerciseId][$dateKey]['minutes'] += $minutes;
            $stats[$exerciseId][$dateKey]['count']++;
            if ($s->isFinished()) {
                $stats[$exerciseId][$dateKey]['completedMinutes'] += $minutes;
                $stats[$exerciseId][$dateKey]['completedCount']++;
            }
        }

        return $stats;
    }

    /**
     * Per exercise: the day of the most recent creditable session (finished
     * or merely "angefangen" - either still means real practice happened)
     * and the learning state of the most recent rated one (a later unrated
     * one doesn't erase the last real assessment) - the inputs
     * ExercisePrioritizer ranks Heute by. Every exercise gets an entry,
     * with nulls where there's nothing yet.
     *
     * @param PracticeSession[] $sessions
     * @return array<int, array{learningState: ?string, lastPracticedOn: ?\DateTimeImmutable}>
     */
    private function latestFinishedByExercise(array $sessions): array
    {
        $latestFinish = [];
        $latestRatedFinish = [];
        $result = [];
        foreach ($sessions as $s) {
            $exercise = $s->getExercise();
            if ($exercise === null || !$s->isCreditable()) {
                continue;
            }
            $exerciseId = $exercise->getId();
            $finishedAt = $s->getFinishedAt();
            $result[$exerciseId] ??= ['learningState' => null, 'lastPracticedOn' => null];

            if (!isset($latestFinish[$exerciseId]) || $finishedAt > $latestFinish[$exerciseId]) {
                $latestFinish[$exerciseId] = $finishedAt;
                $result[$exerciseId]['lastPracticedOn'] = $finishedAt->setTime(0, 0);
            }
            if ($s->getLearningState() !== null && (!isset($latestRatedFinish[$exerciseId]) || $finishedAt > $latestRatedFinish[$exerciseId])) {
                $latestRatedFinish[$exerciseId] = $finishedAt;
                $result[$exerciseId]['learningState'] = $s->getLearningState();
            }
        }

        return $result;
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
