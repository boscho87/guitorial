<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PracticeSession;
use App\Entity\Routine;
use PHPUnit\Framework\TestCase;

final class PracticeSessionSyncTest extends TestCase
{
    public function testLateBeaconCannotRollBackAFinishedRound(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', 'griffbrett');
        $session->applySync(10, 10, 9, true);
        $finishedAt = $session->getFinishedAt();

        $session->applySync(4, 10, 3, false);

        self::assertSame(10, $session->getQuestionIndex());
        self::assertSame(9, $session->getCorrectCount());
        self::assertSame($finishedAt, $session->getFinishedAt());
    }

    public function testDiscardedSessionIsNotRevivedByACompleteBeacon(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', 'griffbrett');
        $session->discard();

        $session->applySync(10, 10, 10, true);

        self::assertSame(PracticeSession::STATUS_DISCARDED, $session->getStatus());
        self::assertNull($session->getFinishedAt());
    }

    public function testProgressOnlyMovesForwardWhileRunning(): void
    {
        $session = new PracticeSession('visitor-1', 'client-1', 'griffbrett');
        $session->applySync(5, 10, 4, false);
        $session->applySync(3, 10, 2, false);

        self::assertSame(5, $session->getQuestionIndex());
        self::assertSame(4, $session->getCorrectCount());
    }

    public function testShareTokenLifecycle(): void
    {
        $routine = new Routine('visitor-1', 'Aufwärmen', 0);
        self::assertNull($routine->getShareToken());

        $first = $routine->ensureShareToken();
        self::assertSame($first, $routine->ensureShareToken());

        $second = $routine->regenerateShareToken();
        self::assertNotSame($first, $second);

        $routine->disableSharing();
        self::assertNull($routine->getShareToken());
    }
}
