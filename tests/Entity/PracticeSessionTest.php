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

    public function testFinishCapsAnAbandonedSessionWithNoHeartbeatAtTheHardCeiling(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 30 * 60 * 60); // 30 hours ago, no touch() ever called

        $session->finish();

        $creditedMinutes = ($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60;
        self::assertSame(180, (int) round($creditedMinutes));
    }

    public function testFinishCapsShortlyAfterTheLastHeartbeatNotAtTheRealAbandonmentGap(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 5 * 60 * 60); // started 5 hours ago
        $this->backdateUpdatedAt($session, 4 * 60 * 60 + 40 * 60); // last heartbeat 4h40 ago - abandoned ~20 min after starting

        $session->finish();

        $creditedMinutes = ($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60;
        // Credited up to the last heartbeat + grace (90s), nowhere near the
        // real ~5h gap between startedAt and the finish() call just now.
        self::assertLessThan(25, $creditedMinutes);
        self::assertGreaterThanOrEqual(20, $creditedMinutes);
    }

    public function testFinishDoesNotClipAVeryShortSessionThatNeverGotAHeartbeat(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', null);
        $this->backdateStartedAt($session, 10); // started 10 seconds ago, no touch() - shorter than the grace period

        $before = time();
        $session->finish();

        // Still the real "now", not clipped down to startedAt + grace.
        self::assertGreaterThanOrEqual($before, $session->getFinishedAt()->getTimestamp());
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
