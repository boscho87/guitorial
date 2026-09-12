<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\Exercise;
use App\Entity\PracticeSession;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;

/**
 * Whether a practice round tied to a diary Exercise should show progress
 * toward that exercise's daily target-minutes instead of the generic
 * question-count progress - and if so, how close today's practice already
 * gets. Returns null whenever there's nothing to show a target for (no
 * current version, or the version has no targetMinutes), which is the
 * signal callers use to fall back to question-count progress.
 */
final class ExerciseTargetProgress
{
    public function __construct(
        private readonly ExerciseVersionRepository $versions,
        private readonly PracticeSessionRepository $sessions,
    ) {
    }

    /**
     * @return array{todayMinutes: int, targetMinutes: int}|null
     */
    public function forExercise(Exercise $exercise, ?PracticeSession $runningSession = null): ?array
    {
        $today = new \DateTimeImmutable('today');
        $targetMinutes = $this->versions->currentFor($exercise, $today)?->getTargetMinutes();
        if ($targetMinutes === null) {
            return null;
        }

        $finished = $this->sessions->finishedForExerciseOnDate($exercise, $today);

        return self::calculate($targetMinutes, $finished, $runningSession, $exercise);
    }

    /**
     * Pure calculation, split out from forExercise() so it's testable
     * without a database: sums already-finished sessions' durations, plus
     * the still-running one's elapsed time if it's for the same exercise.
     *
     * @param PracticeSession[] $finishedSessionsToday
     * @return array{todayMinutes: int, targetMinutes: int}
     */
    public static function calculate(
        int $targetMinutes,
        array $finishedSessionsToday,
        ?PracticeSession $runningSession,
        Exercise $exercise,
        ?int $nowTimestamp = null,
    ): array {
        $minutes = 0.0;
        foreach ($finishedSessionsToday as $session) {
            $minutes += ($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60;
        }

        if (
            $runningSession !== null
            && $runningSession->isRunning()
            && $runningSession->getExercise() === $exercise
        ) {
            $minutes += (($nowTimestamp ?? time()) - $runningSession->getStartedAt()->getTimestamp()) / 60;
        }

        return ['todayMinutes' => (int) round($minutes), 'targetMinutes' => $targetMinutes];
    }
}
