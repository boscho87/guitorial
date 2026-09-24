<?php

declare(strict_types=1);

namespace App\Tests\Domain\Session;

use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class PracticeSessionTest extends TestCase
{
    public function testResumeAfterPauseShiftsStartedAtByThePausedGap(): void
    {
        $session = $this->sessionWith(startedSecondsAgo: 600, lastHeartbeatSecondsAgo: 120);

        $session->resumeAfterPause();

        // 120s since the last heartbeat, minus the 2s grace, is the pause.
        self::assertEqualsWithDelta(600 - 118, time() - $session->getStartedAt()->getTimestamp(), 1);
        self::assertEqualsWithDelta(time(), $session->getUpdatedAt()->getTimestamp(), 1);
    }

    public function testGapWithinGraceIsNotAPause(): void
    {
        $session = $this->sessionWith(startedSecondsAgo: 600, lastHeartbeatSecondsAgo: 1);

        $session->resumeAfterPause();

        self::assertEqualsWithDelta(600, time() - $session->getStartedAt()->getTimestamp(), 1);
    }

    public function testShiftNeverPassesNow(): void
    {
        $session = $this->sessionWith(startedSecondsAgo: 30, lastHeartbeatSecondsAgo: 30);

        $session->resumeAfterPause();

        self::assertLessThanOrEqual(time(), $session->getStartedAt()->getTimestamp());
    }

    public function testAbandonedSessionIsRebasedInsteadOfShifted(): void
    {
        $session = $this->sessionWith(startedSecondsAgo: 400 * 60, lastHeartbeatSecondsAgo: 300 * 60);

        $session->resumeAfterPause();

        self::assertEqualsWithDelta(time(), $session->getStartedAt()->getTimestamp(), 1);
    }

    private function sessionWith(int $startedSecondsAgo, int $lastHeartbeatSecondsAgo): PracticeSession
    {
        $session = new PracticeSession('visitor', 'client-session');
        $this->setProperty($session, 'startedAt', new \DateTimeImmutable(sprintf('-%d seconds', $startedSecondsAgo)));
        $this->setProperty($session, 'updatedAt', new \DateTimeImmutable(sprintf('-%d seconds', $lastHeartbeatSecondsAgo)));

        return $session;
    }

    private function setProperty(PracticeSession $session, string $name, \DateTimeImmutable $value): void
    {
        $property = new \ReflectionProperty(PracticeSession::class, $name);
        $property->setValue($session, $value);
    }
}
