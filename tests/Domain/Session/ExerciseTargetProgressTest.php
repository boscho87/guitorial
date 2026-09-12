<?php

declare(strict_types=1);

namespace App\Tests\Domain\Session;

use App\Domain\Session\ExerciseTargetProgress;
use App\Entity\Exercise;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers ExerciseTargetProgress::calculate() only - the pure arithmetic.
 * forExercise() itself is a thin Doctrine-repository orchestration wrapper
 * (target lookup + today's finished sessions), consistent with this
 * project's convention of not DB-testing repository-touching code.
 */
final class ExerciseTargetProgressTest extends TestCase
{
    public function testNoFinishedOrRunningSessionsGivesZeroMinutes(): void
    {
        $exercise = new Exercise('visitor-1');

        $result = ExerciseTargetProgress::calculate(30, [], null, $exercise);

        self::assertSame(['baseSeconds' => 0, 'todayMinutes' => 0, 'targetMinutes' => 30], $result);
    }

    public function testSumsFinishedSessionsForToday(): void
    {
        $exercise = new Exercise('visitor-1');

        $first = new PracticeSession('visitor-1', 'client-1', 'griffbrett', $exercise);
        $first->backdateStart(12);
        $first->finish();

        $second = new PracticeSession('visitor-1', 'client-2', 'griffbrett', $exercise);
        $second->backdateStart(5);
        $second->finish();

        $result = ExerciseTargetProgress::calculate(30, [$first, $second], null, $exercise);

        self::assertSame(17, $result['todayMinutes']);
        self::assertSame(17 * 60, $result['baseSeconds']);
    }

    public function testBaseSecondsExcludesTheLiveRoundSoTheBrowserCanTickItSeparately(): void
    {
        $exercise = new Exercise('visitor-1');

        $finished = new PracticeSession('visitor-1', 'client-1', 'griffbrett', $exercise);
        $finished->backdateStart(12);
        $finished->finish();

        $running = new PracticeSession('visitor-1', 'client-2', 'griffbrett', $exercise);
        $running->backdateStart(3);
        $now = $running->getStartedAt()->getTimestamp() + 3 * 60;

        $result = ExerciseTargetProgress::calculate(30, [$finished], $running, $exercise, $now);

        self::assertSame(12 * 60, $result['baseSeconds']);
        self::assertSame(15, $result['todayMinutes']);
    }

    public function testAddsStillRunningSessionsElapsedTimeForTheSameExercise(): void
    {
        $exercise = new Exercise('visitor-1');

        $running = new PracticeSession('visitor-1', 'client-1', 'griffbrett', $exercise);
        $running->backdateStart(8);
        $now = $running->getStartedAt()->getTimestamp() + 8 * 60;

        $result = ExerciseTargetProgress::calculate(30, [], $running, $exercise, $now);

        self::assertSame(8, $result['todayMinutes']);
    }

    public function testIgnoresARunningSessionForADifferentExercise(): void
    {
        $exercise = new Exercise('visitor-1');
        $otherExercise = new Exercise('visitor-1');

        $running = new PracticeSession('visitor-1', 'client-1', 'griffbrett', $otherExercise);
        $running->backdateStart(8);
        $now = $running->getStartedAt()->getTimestamp() + 8 * 60;

        $result = ExerciseTargetProgress::calculate(30, [], $running, $exercise, $now);

        self::assertSame(0, $result['todayMinutes']);
    }

    public function testDoesNotDoubleCountASessionThatIsBothFinishedAndPassedAsRunning(): void
    {
        $exercise = new Exercise('visitor-1');

        $session = new PracticeSession('visitor-1', 'client-1', 'griffbrett', $exercise);
        $session->backdateStart(10);
        $session->finish();

        $result = ExerciseTargetProgress::calculate(30, [$session], $session, $exercise);

        self::assertSame(10, $result['todayMinutes']);
    }
}
