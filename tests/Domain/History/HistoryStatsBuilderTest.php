<?php

declare(strict_types=1);

namespace App\Tests\Domain\History;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class HistoryStatsBuilderTest extends TestCase
{
    private HistoryStatsBuilder $builder;
    private int $sessionCounter = 0;

    protected function setUp(): void
    {
        $this->builder = new HistoryStatsBuilder(new ToolRegistry());
    }

    public function testAverageAccuracyExcludesUnfinishedAndScaleGeneratorSessions(): void
    {
        $finishedGraded1 = $this->finished('tonart-erkennen', total: 4, correct: 3);
        $finishedGraded2 = $this->finished('griffbrett', total: 10, correct: 10);
        $unfinished = new PracticeSession('visitor-1', $this->nextSessionId(), 'tonart-erkennen');
        $scaleGenerator = $this->finished('skalen-generator', total: 12, correct: null);

        $result = $this->builder->build([$scaleGenerator, $unfinished, $finishedGraded2, $finishedGraded1]);

        // (75 + 100) / 2 = 87.5 - the ungraded scale-generator round is excluded from the average
        self::assertSame(87.5, $result['averageAccuracy']);
        // but it still counts as a finished round, same as the unfinished one is excluded here
        self::assertSame(3, $result['totalFinishedRounds']);
    }

    public function testAverageAccuracyIsNullWithoutAnyGradedRounds(): void
    {
        $result = $this->builder->build([$this->finished('skalen-generator', total: 5, correct: null)]);

        self::assertNull($result['averageAccuracy']);
        self::assertSame([], $result['series']);
    }

    public function testSeriesCapsAtChartWindowAndOrdersChronologically(): void
    {
        // 32 rounds, most-recent-first (as forVisitor() returns them), accuracy
        // encodes the position so we can assert both the cap and the ordering.
        $sessions = [];
        for ($i = 0; $i < 32; $i++) {
            $sessions[] = $this->finished('griffbrett', total: 100, correct: 32 - $i);
        }

        $result = $this->builder->build($sessions);
        $points = $result['series']['griffbrett']['points'];

        self::assertCount(30, $points);
        // oldest-first: the very last (32nd, oldest kept) round has correct=3 -> accuracy 3.0,
        // the most recent round has correct=32 -> accuracy 32.0
        self::assertSame(3.0, $points[0]['accuracy']);
        self::assertSame(32.0, $points[count($points) - 1]['accuracy']);
    }

    public function testToolWithOnlyOneFinishedGradedRoundGetsNoSeriesButCountsTowardAll(): void
    {
        $tonart1 = $this->finished('tonart-erkennen', total: 5, correct: 5);
        $tonart2 = $this->finished('tonart-erkennen', total: 5, correct: 4);
        $kapoSingle = $this->finished('kapo-trainer', total: 5, correct: 3);

        $result = $this->builder->build([$kapoSingle, $tonart2, $tonart1]);

        self::assertArrayHasKey('tonart-erkennen', $result['series']);
        self::assertArrayNotHasKey('kapo-trainer', $result['series']);
        self::assertCount(3, $result['series']['all']['points']);
    }

    public function testStreakCountsConsecutiveDaysEndingToday(): void
    {
        $today = new \DateTimeImmutable('today');
        $sessions = [
            $this->finishedOn($today),
            $this->finishedOn($today->modify('-1 day')),
            $this->finishedOn($today->modify('-2 days')),
        ];

        $result = $this->builder->build($sessions);

        self::assertSame(3, $result['currentStreak']);
    }

    public function testStreakBreaksOnAGapBeforeToday(): void
    {
        $today = new \DateTimeImmutable('today');
        $sessions = [
            $this->finishedOn($today),
            $this->finishedOn($today->modify('-3 days')),
        ];

        $result = $this->builder->build($sessions);

        self::assertSame(1, $result['currentStreak']);
    }

    public function testStreakHasGraceDayWhenTodayNotYetPracticed(): void
    {
        $today = new \DateTimeImmutable('today');
        $sessions = [
            $this->finishedOn($today->modify('-1 day')),
            $this->finishedOn($today->modify('-2 days')),
            $this->finishedOn($today->modify('-3 days')),
        ];

        $result = $this->builder->build($sessions);

        self::assertSame(3, $result['currentStreak']);
    }

    public function testStreakIsZeroWhenNeitherTodayNorYesterdayHasARound(): void
    {
        $today = new \DateTimeImmutable('today');
        $sessions = [$this->finishedOn($today->modify('-5 days'))];

        $result = $this->builder->build($sessions);

        self::assertSame(0, $result['currentStreak']);
    }

    private function finished(string $tool, int $total, ?int $correct): PracticeSession
    {
        $session = new PracticeSession('visitor-1', $this->nextSessionId(), $tool);
        $session->applySync(questionIndex: $total, totalQuestions: $total, correctCount: $correct, finished: true);

        return $session;
    }

    private function finishedOn(\DateTimeImmutable $date): PracticeSession
    {
        $session = $this->finished('tonart-erkennen', total: 1, correct: 1);

        // applySync() always stamps finishedAt = now() - no public setter exists
        // (and adding one only for this test isn't worth it), so reach past the
        // property directly to pin it to the date the streak test needs.
        (\Closure::bind(function () use ($date): void {
            $this->finishedAt = $date;
        }, $session, PracticeSession::class))();

        return $session;
    }

    private function nextSessionId(): string
    {
        return 'session-' . ++$this->sessionCounter;
    }
}
