<?php

declare(strict_types=1);

namespace App\Tests\Domain\Session;

use App\Domain\Session\PersonalRecords;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class PersonalRecordsTest extends TestCase
{
    // --- calculatePoints() ---------------------------------------------

    public function testImperfectRoundGetsOnlyBasePointsNoBonus(): void
    {
        $current = new PracticeSession('visitor-1', 'client-1', 'tonart-erkennen');
        $current->backdateStart(5);

        $result = PersonalRecords::calculatePoints([], $current, correctCount: 8, totalQuestions: 10, nowTimestamp: time());

        self::assertSame(80, $result['points']);
        self::assertSame(0, $result['timeBonusPoints']);
    }

    public function testPerfectRoundWithNoPriorSameCountRoundGetsNoBonus(): void
    {
        $current = new PracticeSession('visitor-1', 'client-1', 'tonart-erkennen');
        $current->backdateStart(5);
        $now = $current->getStartedAt()->getTimestamp() + 5 * 60;

        $result = PersonalRecords::calculatePoints([], $current, correctCount: 10, totalQuestions: 10, nowTimestamp: $now);

        self::assertSame(100, $result['points']);
        self::assertSame(0, $result['timeBonusPoints']);
    }

    public function testPerfectRoundFasterThanPriorFastestSameCountEarnsABonus(): void
    {
        $prior = $this->historicalSession('client-1', totalQuestions: 10, durationMinutes: 5);

        $current = new PracticeSession('visitor-1', 'client-2', 'tonart-erkennen');
        $current->backdateStart(3);
        $now = $current->getStartedAt()->getTimestamp() + 3 * 60;

        $result = PersonalRecords::calculatePoints([$prior], $current, correctCount: 10, totalQuestions: 10, nowTimestamp: $now);

        // Prior: 5 min (300s). Current: 3 min (180s). Bonus = 300 - 180 = 120s.
        self::assertSame(100 + 120, $result['points']);
        self::assertSame(120, $result['timeBonusPoints']);
    }

    public function testPerfectRoundSlowerThanPriorFastestGetsNoBonusNeverNegative(): void
    {
        $prior = $this->historicalSession('client-1', totalQuestions: 10, durationMinutes: 3);

        $current = new PracticeSession('visitor-1', 'client-2', 'tonart-erkennen');
        $current->backdateStart(5);
        $now = $current->getStartedAt()->getTimestamp() + 5 * 60;

        $result = PersonalRecords::calculatePoints([$prior], $current, correctCount: 10, totalQuestions: 10, nowTimestamp: $now);

        self::assertSame(100, $result['points']);
        self::assertSame(0, $result['timeBonusPoints']);
    }

    public function testBonusBaselineIgnoresSessionsWithADifferentQuestionCount(): void
    {
        $differentCount = $this->historicalSession('client-1', totalQuestions: 5, durationMinutes: 1);

        $current = new PracticeSession('visitor-1', 'client-2', 'tonart-erkennen');
        $current->backdateStart(3);
        $now = $current->getStartedAt()->getTimestamp() + 3 * 60;

        $result = PersonalRecords::calculatePoints([$differentCount], $current, correctCount: 10, totalQuestions: 10, nowTimestamp: $now);

        self::assertSame(0, $result['timeBonusPoints']);
    }

    // --- summarize() -----------------------------------------------------

    public function testUngradedRoundWithNoStoredPointsSummarizesToNull(): void
    {
        $current = new PracticeSession('visitor-1', 'client-1', 'tonart-erkennen');

        self::assertNull(PersonalRecords::summarize([], $current));
    }

    public function testFirstGradedRoundIsItsOwnBestAndNotFlaggedAsNewRecord(): void
    {
        $current = $this->finishedWithPoints('client-1', 80);

        $result = PersonalRecords::summarize([$current], $current);

        self::assertSame(80, $result['points']);
        self::assertSame(80, $result['bestPoints']);
        self::assertFalse($result['isNewRecord']);
    }

    public function testStrictlyMorePointsThanPriorBestIsFlaggedAsANewRecord(): void
    {
        $previous = $this->finishedWithPoints('client-1', 80);
        $current = $this->finishedWithPoints('client-2', 120);

        $result = PersonalRecords::summarize([$previous, $current], $current);

        self::assertTrue($result['isNewRecord']);
        self::assertSame(120, $result['bestPoints']);
    }

    public function testFewerPointsThanPriorBestKeepsThatBestAndIsNotFlagged(): void
    {
        $previous = $this->finishedWithPoints('client-1', 120);
        $current = $this->finishedWithPoints('client-2', 80);

        $result = PersonalRecords::summarize([$previous, $current], $current);

        self::assertFalse($result['isNewRecord']);
        self::assertSame(120, $result['bestPoints']);
    }

    public function testExactTieWithPriorBestIsNotFlaggedAsANewRecord(): void
    {
        $previous = $this->finishedWithPoints('client-1', 100);
        $current = $this->finishedWithPoints('client-2', 100);

        $result = PersonalRecords::summarize([$previous, $current], $current);

        self::assertFalse($result['isNewRecord']);
        self::assertSame(100, $result['bestPoints']);
    }

    private function historicalSession(string $clientSessionId, int $totalQuestions, int $durationMinutes): PracticeSession
    {
        $session = new PracticeSession('visitor-1', $clientSessionId, 'tonart-erkennen');
        $session->backdateStart($durationMinutes);
        $session->applySync($totalQuestions, $totalQuestions, $totalQuestions, true);

        return $session;
    }

    private function finishedWithPoints(string $clientSessionId, int $points): PracticeSession
    {
        $session = new PracticeSession('visitor-1', $clientSessionId, 'tonart-erkennen');
        $session->finish(points: $points, timeBonusPoints: 0);

        return $session;
    }
}
