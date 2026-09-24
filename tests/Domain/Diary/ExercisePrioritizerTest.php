<?php

declare(strict_types=1);

namespace App\Tests\Domain\Diary;

use App\Domain\Diary\ExercisePrioritizer;
use App\Entity\Exercise;
use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class ExercisePrioritizerTest extends TestCase
{
    // A Wednesday - 5 days left in the ISO week (Wed..Sun) when counting today.
    private const WEDNESDAY = '2026-09-23';

    private ExercisePrioritizer $prioritizer;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->prioritizer = new ExercisePrioritizer();
        $this->today = new \DateTimeImmutable(self::WEDNESDAY);
    }

    public function testDifficultComesBeforeConfident(): void
    {
        $confident = $this->row('Sitzt', PracticeSession::LEARNING_STATE_CONFIDENT);
        $difficult = $this->row('Zäh', PracticeSession::LEARNING_STATE_DIFFICULT);

        $sorted = $this->prioritizer->sort([$confident, $difficult], $this->today);

        self::assertSame(['Zäh', 'Sitzt'], $this->titles($sorted));
        self::assertSame(ExercisePrioritizer::REASON_DIFFICULT, $sorted[0]['priorityReason']);
    }

    public function testDifficultOutranksNeverPracticed(): void
    {
        $new = $this->row('Neu', null);
        $difficult = $this->row('Zäh', PracticeSession::LEARNING_STATE_DIFFICULT, lastPracticedDaysAgo: 1);

        self::assertSame(['Zäh', 'Neu'], $this->titles($this->prioritizer->sort([$new, $difficult], $this->today)));
    }

    public function testDoneTodaySinksToTheBottomKeepingItsOrderAmongTheDone(): void
    {
        $doneA = $this->row('Fertig A', PracticeSession::LEARNING_STATE_DIFFICULT, checked: true);
        $open = $this->row('Offen', PracticeSession::LEARNING_STATE_CONFIDENT);
        $doneB = $this->row('Fertig B', PracticeSession::LEARNING_STATE_DIFFICULT, checked: true);

        $sorted = $this->prioritizer->sort([$doneA, $open, $doneB], $this->today);

        self::assertSame(['Offen', 'Fertig A', 'Fertig B'], $this->titles($sorted));
    }

    public function testRunningSessionComesFirst(): void
    {
        $difficult = $this->row('Zäh', PracticeSession::LEARNING_STATE_DIFFICULT);
        $running = $this->row('Läuft gerade', PracticeSession::LEARNING_STATE_CONFIDENT, runningSessionId: 7);

        $sorted = $this->prioritizer->sort([$difficult, $running], $this->today);

        self::assertSame(['Läuft gerade', 'Zäh'], $this->titles($sorted));
    }

    public function testUrgentWeeklyGoalBeatsMetWeeklyGoal(): void
    {
        // 5 days left (Wed-Sun) and 5 still open: must practice today.
        $urgent = $this->row('Dringend', null, rhythm: ExerciseVersion::RHYTHM_WEEKLY, weeklyTarget: 5, weeklyDone: 0);
        $met = $this->row('Schon erreicht', null, rhythm: ExerciseVersion::RHYTHM_WEEKLY, weeklyTarget: 2, weeklyDone: 2);

        $sorted = $this->prioritizer->sort([$met, $urgent], $this->today);

        self::assertSame(['Dringend', 'Schon erreicht'], $this->titles($sorted));
        self::assertSame(ExercisePrioritizer::REASON_WEEKLY_GOAL, $sorted[0]['priorityReason']);
    }

    public function testLongAgoBeatsPracticedYesterdayAtEqualRating(): void
    {
        $recent = $this->row('Gestern', PracticeSession::LEARNING_STATE_IMPROVING, lastPracticedDaysAgo: 1);
        $stale = $this->row('Lange her', PracticeSession::LEARNING_STATE_IMPROVING, lastPracticedDaysAgo: 12);

        $sorted = $this->prioritizer->sort([$recent, $stale], $this->today);

        self::assertSame(['Lange her', 'Gestern'], $this->titles($sorted));
        self::assertSame(ExercisePrioritizer::REASON_LONG_AGO, $sorted[0]['priorityReason']);
    }

    public function testNeverPracticedIsFlaggedAsNew(): void
    {
        $sorted = $this->prioritizer->sort([$this->row('Frisch', null)], $this->today);

        self::assertSame(ExercisePrioritizer::REASON_NEW, $sorted[0]['priorityReason']);
    }

    public function testEqualScoresKeepTheRoutineOrder(): void
    {
        $rows = [
            $this->row('A', PracticeSession::LEARNING_STATE_IMPROVING, lastPracticedDaysAgo: 3),
            $this->row('B', PracticeSession::LEARNING_STATE_IMPROVING, lastPracticedDaysAgo: 3),
            $this->row('C', PracticeSession::LEARNING_STATE_IMPROVING, lastPracticedDaysAgo: 3),
        ];

        self::assertSame(['A', 'B', 'C'], $this->titles($this->prioritizer->sort($rows, $this->today)));
    }

    public function testOnlyReordersTheRowsItIsGiven(): void
    {
        $rows = [$this->row('X', PracticeSession::LEARNING_STATE_CONFIDENT), $this->row('Y', PracticeSession::LEARNING_STATE_DIFFICULT)];

        $sorted = $this->prioritizer->sort($rows, $this->today);

        self::assertCount(2, $sorted);
        self::assertEqualsCanonicalizing(['X', 'Y'], $this->titles($sorted));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $title,
        ?string $learningState,
        bool $checked = false,
        ?int $runningSessionId = null,
        string $rhythm = ExerciseVersion::RHYTHM_NONE,
        ?int $weeklyTarget = null,
        ?int $weeklyDone = null,
        ?int $lastPracticedDaysAgo = null,
    ): array {
        $exercise = new Exercise('visitor-1');
        $version = new ExerciseVersion($exercise, $title, null, null, $rhythm, $weeklyTarget, $this->today->modify('-30 days'));

        return [
            'version' => $version,
            'checked' => $checked,
            'practicedMinutes' => null,
            'sessionCount' => 0,
            'runningSessionId' => $runningSessionId,
            'runningSessionTool' => null,
            'weeklyDone' => $weeklyDone,
            'weeklyTarget' => $weeklyTarget,
            'learningState' => $learningState,
            'lastPracticedOn' => $lastPracticedDaysAgo !== null ? $this->today->modify("-{$lastPracticedDaysAgo} days") : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['version']->getTitle(), $rows);
    }
}
