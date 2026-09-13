<?php

declare(strict_types=1);

namespace App\Tests\Domain\Session;

use App\Domain\Session\PersonalRecords;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class PersonalRecordsTest extends TestCase
{
    public function testFirstEverRoundGetsNoRecordFlags(): void
    {
        $current = $this->finishedSession('client-1', 8, 10, 4);

        $result = PersonalRecords::calculate([$current], $current, 8, 10);

        self::assertFalse($result['isNewScoreRecord']);
        self::assertFalse($result['isNewSpeedRecord']);
        self::assertSame(8, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    public function testStrictlyBetterScoreIsFlaggedAndUpdatesTheBest(): void
    {
        $previous = $this->finishedSession('client-1', 6, 10, 4);
        $current = $this->finishedSession('client-2', 9, 10, 4);

        $result = PersonalRecords::calculate([$previous, $current], $current, 9, 10);

        self::assertTrue($result['isNewScoreRecord']);
        self::assertSame(9, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    public function testExactTieIsNotFlaggedAsANewRecord(): void
    {
        $previous = $this->finishedSession('client-1', 8, 10, 4);
        $current = $this->finishedSession('client-2', 8, 10, 4);

        $result = PersonalRecords::calculate([$previous, $current], $current, 8, 10);

        self::assertFalse($result['isNewScoreRecord']);
        self::assertSame(8, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    public function testWorseRoundKeepsThePreviousBestAndIsNotFlagged(): void
    {
        $previous = $this->finishedSession('client-1', 9, 10, 4);
        $current = $this->finishedSession('client-2', 5, 10, 4);

        $result = PersonalRecords::calculate([$previous, $current], $current, 5, 10);

        self::assertFalse($result['isNewScoreRecord']);
        self::assertSame(9, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    public function testSpeedComparisonIgnoresSessionsWithADifferentQuestionCount(): void
    {
        $fastButDifferentCount = $this->finishedSession('client-1', 3, 3, 1);
        $current = $this->finishedSession('client-2', 8, 10, 6);

        $result = PersonalRecords::calculate([$fastButDifferentCount, $current], $current, 8, 10);

        // No prior round with totalQuestions = 10 to compare against - not
        // flagged (nothing was actually beaten), same as a true first round.
        self::assertFalse($result['isNewSpeedRecord']);
        self::assertSame(6 * 60, $result['fastestSeconds']);
        self::assertSame(10, $result['fastestQuestionCount']);
    }

    public function testFasterRoundWithTheSameQuestionCountIsFlaggedAndUpdatesTheBest(): void
    {
        $previous = $this->finishedSession('client-1', 8, 10, 6);
        $current = $this->finishedSession('client-2', 8, 10, 3);

        $result = PersonalRecords::calculate([$previous, $current], $current, 8, 10);

        self::assertTrue($result['isNewSpeedRecord']);
        self::assertSame(3 * 60, $result['fastestSeconds']);
    }

    public function testSlowerRoundKeepsThePreviousFastestAndIsNotFlagged(): void
    {
        $previous = $this->finishedSession('client-1', 8, 10, 3);
        $current = $this->finishedSession('client-2', 8, 10, 6);

        $result = PersonalRecords::calculate([$previous, $current], $current, 8, 10);

        self::assertFalse($result['isNewSpeedRecord']);
        self::assertSame(3 * 60, $result['fastestSeconds']);
    }

    public function testCrossMultiplicationRanksHigherAccuracyAtFewerQuestionsAboveLowerAccuracyAtMore(): void
    {
        // 8/10 = 80%, 24/30 = 80% exactly - but a real, non-tied comparison:
        // 8/10 (80%) should beat 21/30 (70%).
        $longerButLessAccurate = $this->finishedSession('client-1', 21, 30, 10);
        $current = $this->finishedSession('client-2', 8, 10, 4);

        $result = PersonalRecords::calculate([$longerButLessAccurate, $current], $current, 8, 10);

        self::assertTrue($result['isNewScoreRecord']);
        self::assertSame(8, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    public function testCrossMultiplicationRanksAPerfectLongerRoundAboveAPerfectShorterOne(): void
    {
        $shortPerfect = $this->finishedSession('client-1', 3, 3, 1);
        $current = $this->finishedSession('client-2', 10, 10, 4);

        $result = PersonalRecords::calculate([$shortPerfect, $current], $current, 10, 10);

        self::assertTrue($result['isNewScoreRecord']);
        self::assertSame(10, $result['bestCorrect']);
        self::assertSame(10, $result['bestTotal']);
    }

    private function finishedSession(string $clientSessionId, int $correct, int $total, int $durationMinutes): PracticeSession
    {
        $session = new PracticeSession('visitor-1', $clientSessionId, 'tonart-erkennen');
        $session->backdateStart($durationMinutes);
        $session->applySync($total, $total, $correct, true);

        return $session;
    }
}
