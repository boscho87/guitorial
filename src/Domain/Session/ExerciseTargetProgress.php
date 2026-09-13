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
     * @return array{todaySeconds: int, todayMinutes: int, targetMinutes: int}|null
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
     * The running session's contribution is capped at its own updatedAt,
     * not wall-clock "now" - updatedAt only advances via the practice-sync
     * heartbeat (~every 20s) that runs while the practice turbo-frame is
     * actually connected, and stops the moment the visitor navigates away
     * (Stimulus disconnect() clears its interval). Using raw "now" instead
     * would keep counting a round as "practiced" for however many real
     * hours it sat abandoned before being resumed or discarded - exactly
     * the bug reported: the exercise looked like it kept running after
     * switching away to another screen.
     *
     * todaySeconds is exposed (exact, already capped) so the browser can
     * tick the progress bar live for time spent on THIS page view, seeded
     * from this value at connect - instead of only moving once per question
     * answered, or extrapolating from the round's original start time.
     *
     * @param PracticeSession[] $finishedSessionsToday
     * @return array{todaySeconds: int, todayMinutes: int, targetMinutes: int}
     */
    public static function calculate(
        int $targetMinutes,
        array $finishedSessionsToday,
        ?PracticeSession $runningSession,
        Exercise $exercise,
        ?int $nowTimestamp = null,
    ): array {
        $finishedSeconds = 0;
        foreach ($finishedSessionsToday as $session) {
            $finishedSeconds += $session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp();
        }

        $liveSeconds = 0;
        if (
            $runningSession !== null
            && $runningSession->isRunning()
            && $runningSession->getExercise() === $exercise
        ) {
            $lastHeartbeat = min($nowTimestamp ?? time(), $runningSession->getUpdatedAt()->getTimestamp());
            $liveSeconds = max(0, $lastHeartbeat - $runningSession->getStartedAt()->getTimestamp());
        }

        $todaySeconds = $finishedSeconds + $liveSeconds;

        return [
            'todaySeconds' => $todaySeconds,
            'todayMinutes' => (int) round($todaySeconds / 60),
            'targetMinutes' => $targetMinutes,
        ];
    }
}
