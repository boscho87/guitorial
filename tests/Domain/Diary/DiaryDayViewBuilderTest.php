<?php

declare(strict_types=1);

namespace App\Tests\Domain\Diary;

use App\Domain\Diary\DiaryDayViewBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\DiaryCheck;
use App\Entity\DiaryItem;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class DiaryDayViewBuilderTest extends TestCase
{
    private DiaryDayViewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DiaryDayViewBuilder(new ToolRegistry());
    }

    public function testManualItemIsCheckedOnlyWhenACheckRowExistsForThatDay(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Neue Akkorde', DiaryItem::CATEGORY_PRACTICE, null, null, $today->modify('-3 days'));
        $check = new DiaryCheck($item, $today);

        $withCheck = $this->builder->buildDay([$item], [$check], [], $today);
        $withoutCheck = $this->builder->buildDay([$item], [], [], $today);

        self::assertTrue($withCheck[0]['checked']);
        self::assertFalse($withoutCheck[0]['checked']);
    }

    public function testAutoTrackedItemChecksOnlyAtOrOverTargetMinutes(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Tonart erkennen üben', DiaryItem::CATEGORY_THEORY, 'tonart-erkennen', 10, $today->modify('-3 days'));

        $under = $this->finishedSessionOn('tonart-erkennen', $today, minutes: 5);
        $atTarget = $this->finishedSessionOn('tonart-erkennen', $today, minutes: 10);

        $rowUnder = $this->builder->buildDay([$item], [], [$under], $today)[0];
        $rowAt = $this->builder->buildDay([$item], [], [$atTarget], $today)[0];

        self::assertFalse($rowUnder['checked']);
        self::assertSame(5, $rowUnder['practicedMinutes']);
        self::assertTrue($rowAt['checked']);
        self::assertSame(10, $rowAt['practicedMinutes']);
    }

    public function testAutoTrackedMinutesSumAcrossMultipleSessionsSameDay(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Skalen üben', DiaryItem::CATEGORY_THEORY, 'skalen-generator', 10, $today->modify('-3 days'));

        $first = $this->finishedSessionOn('skalen-generator', $today, minutes: 6);
        $second = $this->finishedSessionOn('skalen-generator', $today, minutes: 6);

        $row = $this->builder->buildDay([$item], [], [$first, $second], $today)[0];

        self::assertSame(12, $row['practicedMinutes']);
        self::assertTrue($row['checked']);
    }

    public function testItemNotYetValidOnThatDayIsExcluded(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Neu ab heute', DiaryItem::CATEGORY_PRACTICE, null, null, $today);

        $yesterday = $this->builder->buildDay([$item], [], [], $today->modify('-1 day'));

        self::assertSame([], $yesterday);
    }

    public function testClosedItemIsExcludedOnItsCloseDayButActiveTheDayBefore(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Altes Ziel', DiaryItem::CATEGORY_PRACTICE, null, null, $today->modify('-10 days'));
        $item->closeAsOf($today);

        $onCloseDay = $this->builder->buildDay([$item], [], [], $today);
        $dayBefore = $this->builder->buildDay([$item], [], [], $today->modify('-1 day'));

        self::assertSame([], $onCloseDay);
        self::assertCount(1, $dayBefore);
    }

    public function testEditedItemResolvesOldLabelInThePastAndNewLabelTodayAndTomorrow(): void
    {
        $today = new \DateTimeImmutable('today');
        $old = $this->item('Altes Label', DiaryItem::CATEGORY_PRACTICE, null, null, $today->modify('-5 days'));
        $old->closeAsOf($today);
        $new = $this->item('Neues Label', DiaryItem::CATEGORY_PRACTICE, null, null, $today);

        $versions = [$old, $new];

        $yesterday = $this->builder->buildDay($versions, [], [], $today->modify('-1 day'));
        $todayRows = $this->builder->buildDay($versions, [], [], $today);
        $tomorrow = $this->builder->buildDay($versions, [], [], $today->modify('+1 day'));

        self::assertSame('Altes Label', $yesterday[0]['item']->getLabel());
        self::assertSame('Neues Label', $todayRows[0]['item']->getLabel());
        self::assertSame('Neues Label', $tomorrow[0]['item']->getLabel());
    }

    public function testBuildRecentRatiosAcrossDifferingActiveItemSets(): void
    {
        $today = new \DateTimeImmutable('today');
        // active for the whole window
        $longStanding = $this->item('Aufwärmen', DiaryItem::CATEGORY_PRACTICE, null, null, $today->modify('-10 days'));
        // only added 2 days ago
        $recent = $this->item('Neues Stück', DiaryItem::CATEGORY_PRACTICE, null, null, $today->modify('-2 days'));

        $checks = [
            new DiaryCheck($longStanding, $today->modify('-3 days')),
            new DiaryCheck($longStanding, $today->modify('-1 days')),
            new DiaryCheck($recent, $today->modify('-1 days')),
        ];

        $ratios = $this->builder->buildRecentRatios([$longStanding, $recent], $checks, [], $today, 7);

        self::assertCount(7, $ratios);
        // 4 days ago: only $longStanding active, unchecked
        $fourDaysAgo = $ratios[array_search($today->modify('-4 days')->format('Y-m-d'), array_map(fn ($r) => $r['date']->format('Y-m-d'), $ratios), true)];
        self::assertSame(['done' => 0, 'total' => 1], ['done' => $fourDaysAgo['done'], 'total' => $fourDaysAgo['total']]);
        // 1 day ago: both active, both checked
        $oneDayAgo = $ratios[array_search($today->modify('-1 days')->format('Y-m-d'), array_map(fn ($r) => $r['date']->format('Y-m-d'), $ratios), true)];
        self::assertSame(['done' => 2, 'total' => 2], ['done' => $oneDayAgo['done'], 'total' => $oneDayAgo['total']]);
    }

    public function testCategoryPassesThroughUnchanged(): void
    {
        $today = new \DateTimeImmutable('today');
        $item = $this->item('Punkt', DiaryItem::CATEGORY_THEORY, null, null, $today);

        $row = $this->builder->buildDay([$item], [], [], $today)[0];

        self::assertSame(DiaryItem::CATEGORY_THEORY, $row['item']->getCategory());
    }

    private function item(string $label, string $category, ?string $linkedTool, ?int $targetMinutes, \DateTimeImmutable $validFrom): DiaryItem
    {
        return new DiaryItem('visitor-1', $label, $category, $linkedTool, $targetMinutes, $validFrom);
    }

    private function finishedSessionOn(string $tool, \DateTimeImmutable $date, int $minutes): PracticeSession
    {
        $session = new PracticeSession('visitor-1', 'session-'.uniqid(), $tool);
        $session->applySync(questionIndex: 1, totalQuestions: 1, correctCount: 1, finished: true);

        $started = $date->setTime(10, 0);
        $finished = $started->modify("+{$minutes} minutes");

        (\Closure::bind(function () use ($started, $finished): void {
            $this->startedAt = $started;
            $this->finishedAt = $finished;
        }, $session, PracticeSession::class))();

        return $session;
    }
}
