<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class PracticeSessionTest extends TestCase
{
    public function testFinishUsesTheRealCurrentMomentForANormalSession(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);

        $before = time();
        $session->finish();
        $after = time();

        self::assertGreaterThanOrEqual($before, $session->getFinishedAt()->getTimestamp());
        self::assertLessThanOrEqual($after, $session->getFinishedAt()->getTimestamp());
    }

    public function testFinishCapsImmediatelyWhenNoHeartbeatEverArrivedRegardlessOfAge(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 30 * 60 * 60); // 30 hours ago, no touch() ever called

        $session->finish();

        // Capped at startedAt + the small grace, not the 180-minute outer
        // ceiling - the absence of any heartbeat no longer buys a looser
        // cap. A real, continuously-used session always gets at least one
        // heartbeat almost immediately (see session_heartbeat_controller.js
        // pinging on connect()), so "no heartbeat ever" now means "quickly
        // abandoned", not "heartbeat mechanism broken".
        $creditedSeconds = $session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp();
        self::assertLessThan(5, $creditedSeconds);
    }

    public function testFinishCapsShortlyAfterTheLastHeartbeatNotAtTheRealAbandonmentGap(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 5 * 60 * 60); // started 5 hours ago
        $this->backdateUpdatedAt($session, 4 * 60 * 60 + 40 * 60); // last heartbeat 4h40 ago - abandoned ~20 min after starting

        $session->finish();

        $creditedMinutes = ($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60;
        // Credited up to the last heartbeat + a couple seconds of grace,
        // nowhere near the real ~5h gap between startedAt and the finish()
        // call just now.
        self::assertLessThan(21, $creditedMinutes);
        self::assertGreaterThanOrEqual(20, $creditedMinutes);
    }

    public function testFinishStopsAtTheHardCeilingEvenWithContinuousHeartbeats(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 4 * 60 * 60); // started 4 hours ago
        $this->backdateUpdatedAt($session, 1); // a heartbeat landed just now - genuinely still active

        $session->finish();

        // MAX_SESSION_MINUTES (180) still applies as an absolute outer
        // ceiling regardless of how fresh the last heartbeat is.
        $creditedMinutes = ($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60;
        self::assertSame(180, (int) round($creditedMinutes));
    }

    public function testFinishWithDifficultLearningStateLandsAsStartedNotFinished(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);

        $session->finish(PracticeSession::LEARNING_STATE_DIFFICULT);

        self::assertSame(PracticeSession::STATUS_STARTED, $session->getStatus());
        self::assertFalse($session->isFinished());
        self::assertTrue($session->isCreditable());
    }

    public function testFinishWithImprovingLearningStateLandsAsStartedNotFinished(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);

        $session->finish(PracticeSession::LEARNING_STATE_IMPROVING);

        self::assertSame(PracticeSession::STATUS_STARTED, $session->getStatus());
        self::assertFalse($session->isFinished());
        self::assertTrue($session->isCreditable());
    }

    public function testFinishWithConfidentLearningStateIsAGenuineCompletion(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);

        $session->finish(PracticeSession::LEARNING_STATE_CONFIDENT);

        self::assertSame(PracticeSession::STATUS_FINISHED, $session->getStatus());
        self::assertTrue($session->isFinished());
        self::assertTrue($session->isCreditable());
    }

    public function testFinishWithNoLearningStateIsAGenuineCompletion(): void
    {
        // completeManually()'s "mark done" path, and bare tool rounds, never
        // pass a learningState at all - must stay a real completion.
        $session = new PracticeSession('visitor-1', 'client-1', null);

        $session->finish();

        self::assertSame(PracticeSession::STATUS_FINISHED, $session->getStatus());
    }

    public function testDiscardedAndRunningSessionsAreNotCreditable(): void
    {
        $discarded = new PracticeSession('visitor-1', 'client-1', null);
        $discarded->discard();
        self::assertFalse($discarded->isCreditable());

        $running = new PracticeSession('visitor-1', 'client-2', null);
        self::assertFalse($running->isCreditable());
    }

    private function backdateStartedAt(PracticeSession $session, int $seconds): void
    {
        (\Closure::bind(function () use ($seconds): void {
            $this->startedAt = $this->startedAt->modify(sprintf('-%d seconds', $seconds));
            $this->updatedAt = $this->startedAt;
        }, $session, PracticeSession::class))();
    }

    private function backdateUpdatedAt(PracticeSession $session, int $secondsAgo): void
    {
        (\Closure::bind(function () use ($secondsAgo): void {
            $this->updatedAt = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $secondsAgo));
        }, $session, PracticeSession::class))();
    }
}
