<?php

declare(strict_types=1);

namespace App\Tests\Domain\Diary;

use App\Domain\Diary\ExerciseDayViewBuilder;
use App\Entity\Exercise;
use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;
use App\Entity\Routine;
use App\Entity\RoutineItem;
use PHPUnit\Framework\TestCase;

final class ExerciseDayViewBuilderTest extends TestCase
{
    private ExerciseDayViewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ExerciseDayViewBuilder();
    }

    public function testOhneVorgabeIsCheckedOnlyWhenAFinishedSessionExistsThatDay(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Aufwärmen', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-3 days'));

        $withSession = $this->builder->buildDay([$version], [$this->finishedSession($exercise, $today, 1)], $today);
        $withoutSession = $this->builder->buildDay([$version], [], $today);

        self::assertTrue($withSession[0]['checked']);
        self::assertFalse($withoutSession[0]['checked']);
    }

    public function testTargetMinutesChecksOnlyAtOrOverTarget(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Tonart erkennen', 10, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-3 days'));

        $under = $this->builder->buildDay([$version], [$this->finishedSession($exercise, $today, 5)], $today)[0];
        $atTarget = $this->builder->buildDay([$version], [$this->finishedSession($exercise, $today, 10)], $today)[0];

        self::assertFalse($under['checked']);
        self::assertSame(5, $under['practicedMinutes']);
        self::assertTrue($atTarget['checked']);
        self::assertSame(10, $atTarget['practicedMinutes']);
    }

    public function testMinutesSumAcrossMultipleSessionsSameDay(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Skalen üben', 10, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-3 days'));

        $sessions = [$this->finishedSession($exercise, $today, 6), $this->finishedSession($exercise, $today, 6)];
        $row = $this->builder->buildDay([$version], $sessions, $today)[0];

        self::assertSame(12, $row['practicedMinutes']);
        self::assertTrue($row['checked']);
    }

    public function testExerciseNotYetValidOnThatDayIsExcluded(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Neu ab heute', null, ExerciseVersion::RHYTHM_NONE, null, $today);

        self::assertSame([], $this->builder->buildDay([$version], [], $today->modify('-1 day')));
    }

    public function testArchivedExerciseIsExcludedOnArchiveDayButActiveTheDayBefore(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Altes Ziel', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));
        $exercise->archive($today);

        self::assertSame([], $this->builder->buildDay([$version], [], $today));
        self::assertCount(1, $this->builder->buildDay([$version], [], $today->modify('-1 day')));
    }

    public function testEditedVersionResolvesOldTitleInThePastAndNewTitleTodayAndTomorrow(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $old = $this->version($exercise, 'Altes Label', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-5 days'));
        $old->closeAsOf($today);
        $new = $this->version($exercise, 'Neues Label', null, ExerciseVersion::RHYTHM_NONE, null, $today);

        $versions = [$old, $new];

        self::assertSame('Altes Label', $this->builder->buildDay($versions, [], $today->modify('-1 day'))[0]['version']->getTitle());
        self::assertSame('Neues Label', $this->builder->buildDay($versions, [], $today)[0]['version']->getTitle());
        self::assertSame('Neues Label', $this->builder->buildDay($versions, [], $today->modify('+1 day'))[0]['version']->getTitle());
    }

    public function testWeeklyRhythmExposesDoneAndTargetCountsForTheCurrentWeek(): void
    {
        $today = new \DateTimeImmutable('2026-09-09'); // a Wednesday
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Repertoire', null, ExerciseVersion::RHYTHM_WEEKLY, 3, $today->modify('-30 days'));

        // one done Monday (this week), one done today - 2 of 3 so far
        $sessions = [
            $this->finishedSession($exercise, $today->modify('-2 days'), 1),
            $this->finishedSession($exercise, $today, 1),
        ];

        $row = $this->builder->buildDay([$version], $sessions, $today)[0];

        self::assertSame(2, $row['weeklyDone']);
        self::assertSame(3, $row['weeklyTarget']);
    }

    public function testAngefangenSessionCountsMinutesButNeverChecksOffByItself(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Zäh geübt', 10, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-3 days'));

        $row = $this->builder->buildDay([$version], [$this->startedSession($exercise, $today, 15)], $today)[0];

        self::assertFalse($row['checked']);
        self::assertSame(15, $row['practicedMinutes']);
    }

    public function testAngefangenSessionAloneChecksOffAnOhneVorgabeExerciseEither(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Frei, zäh', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-3 days'));

        $row = $this->builder->buildDay([$version], [$this->startedSession($exercise, $today, 5)], $today)[0];

        self::assertFalse($row['checked']);
    }

    public function testCombinedAngefangenAndAbgeschlossenMinutesCountTowardTheTargetOnceGenuinelyCompleted(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Gemischt', 10, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-3 days'));

        // 6 "zäh" minutes earlier today, then a later finished session that
        // alone would miss the target (4 < 10) - only the genuinely
        // completed minutes may check it off, and 4 isn't enough on its own.
        $sessions = [$this->startedSession($exercise, $today, 6), $this->finishedSession($exercise, $today, 4)];
        $row = $this->builder->buildDay([$version], $sessions, $today)[0];

        self::assertSame(10, $row['practicedMinutes']); // shown total includes the "zäh" time
        self::assertFalse($row['checked']); // but only 4 completed minutes count toward the 10-minute target
    }

    public function testRunningSessionIdIsExposedWhenAnExerciseHasALaufendSession(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Laufend', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-3 days'));
        $running = $this->runningSession($exercise, 42);

        $row = $this->builder->buildDay([$version], [$running], $today)[0];

        self::assertSame(42, $row['runningSessionId']);
    }

    public function testBuildRecentRatiosExcludesOhneVorgabeFromTheDenominator(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Frei üben', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));

        $ratios = $this->builder->buildRecentRatios([$version], [], $today, 7);

        foreach ($ratios as $day) {
            self::assertSame(0, $day['total']);
        }
    }

    public function testBuildRecentRatiosStopsCountingAWeeklyExerciseAsPlannedOnceItsGoalIsMet(): void
    {
        $today = new \DateTimeImmutable('2026-09-09'); // a Wednesday
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Wöchentlich', null, ExerciseVersion::RHYTHM_WEEKLY, 1, $today->modify('-30 days'));

        // goal (1x/week) already met on Monday this week
        $sessions = [$this->finishedSession($exercise, $today->modify('-2 days'), 1)];

        $ratios = $this->builder->buildRecentRatios([$version], $sessions, $today, 7);
        $todayRatio = $ratios[array_search($today->format('Y-m-d'), array_map(static fn (array $r) => $r['date']->format('Y-m-d'), $ratios), true)];

        self::assertSame(0, $todayRatio['total']); // no longer "planned" today - already fulfilled this week
    }

    public function testBuildRecentRatiosShowsEveryDayAsUntrackedWithoutAnySessionOnRecord(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Aufwärmen', null, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-10 days'));

        // No sessions at all - e.g. right after "gesamten Verlauf löschen" -
        // every day, including today, should read as untracked (total: 0),
        // never as a 0/X miss for a day nothing proves happened or not.
        $ratios = $this->builder->buildRecentRatios([$version], [], $today, 7);

        foreach ($ratios as $day) {
            self::assertSame(0, $day['total']);
        }
    }

    public function testBuildRecentRatiosOnlyCountsDaysFromTheOldestRemainingSessionOnwards(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1);
        $version = $this->version($exercise, 'Aufwärmen', null, ExerciseVersion::RHYTHM_DAILY, null, $today->modify('-10 days'));

        // Only one session left, from today - as if history was just wiped
        // and today is the first thing logged since.
        $sessions = [$this->finishedSession($exercise, $today, 1)];
        $ratios = $this->builder->buildRecentRatios([$version], $sessions, $today, 7);

        $byDate = [];
        foreach ($ratios as $day) {
            $byDate[$day['date']->format('Y-m-d')] = $day;
        }

        self::assertSame(0, $byDate[$today->modify('-1 day')->format('Y-m-d')]['total']);
        self::assertSame(1, $byDate[$today->format('Y-m-d')]['total']);
        self::assertSame(1, $byDate[$today->format('Y-m-d')]['done']);
    }

    public function testBuildDayForRoutineOrdersRowsByRoutineNotByVersionOrder(): void
    {
        $today = new \DateTimeImmutable('today');
        $exerciseA = $this->exercise(1);
        $exerciseB = $this->exercise(2);
        $exerciseC = $this->exercise(3);
        $versionA = $this->version($exerciseA, 'Aufwärmen', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));
        $versionB = $this->version($exerciseB, 'Boogie Riff', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));
        $versionC = $this->version($exerciseC, 'Chord changes', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));

        $routine = new Routine('visitor-1', 'Abendroutine', 0);
        // Deliberately reversed relative to $allVersions/alphabetical order.
        $routine->addItem(new RoutineItem($routine, $exerciseC, 0));
        $routine->addItem(new RoutineItem($routine, $exerciseA, 1));
        $routine->addItem(new RoutineItem($routine, $exerciseB, 2));

        $rows = $this->builder->buildDayForRoutine($routine, [$versionA, $versionB, $versionC], [], $today);

        self::assertSame(['Chord changes', 'Aufwärmen', 'Boogie Riff'], array_map(
            static fn (array $row): string => $row['version']->getTitle(),
            $rows,
        ));
    }

    public function testBuildDayForRoutineSkipsAnExerciseThatIsNoLongerActive(): void
    {
        $today = new \DateTimeImmutable('today');
        $archivedExercise = $this->exercise(1, archivedAt: $today->modify('-1 day'));
        $activeExercise = $this->exercise(2);
        $archivedVersion = $this->version($archivedExercise, 'Archiviert', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));
        $activeVersion = $this->version($activeExercise, 'Aktiv', null, ExerciseVersion::RHYTHM_NONE, null, $today->modify('-10 days'));

        $routine = new Routine('visitor-1', 'Routine', 0);
        $routine->addItem(new RoutineItem($routine, $archivedExercise, 0));
        $routine->addItem(new RoutineItem($routine, $activeExercise, 1));

        $rows = $this->builder->buildDayForRoutine($routine, [$archivedVersion, $activeVersion], [], $today);

        self::assertCount(1, $rows);
        self::assertSame('Aktiv', $rows[0]['version']->getTitle());
    }

    private function exercise(int $id, ?\DateTimeImmutable $archivedAt = null): Exercise
    {
        $exercise = new Exercise('visitor-1');
        (\Closure::bind(function () use ($id): void {
            $this->id = $id;
        }, $exercise, Exercise::class))();
        if ($archivedAt !== null) {
            $exercise->archive($archivedAt);
        }

        return $exercise;
    }

    private function version(
        Exercise $exercise,
        string $title,
        ?int $targetMinutes,
        string $rhythm,
        ?int $repeatCount,
        \DateTimeImmutable $validFrom,
    ): ExerciseVersion {
        return new ExerciseVersion($exercise, $title, null, $targetMinutes, $rhythm, $repeatCount, $validFrom);
    }

    private function finishedSession(Exercise $exercise, \DateTimeImmutable $date, int $minutes): PracticeSession
    {
        $session = new PracticeSession('visitor-1', 'session-'.uniqid(), null, $exercise);
        $session->applySync(questionIndex: 0, totalQuestions: null, correctCount: null, finished: true);

        $started = $date->setTime(10, 0);
        $finished = $started->modify("+{$minutes} minutes");

        (\Closure::bind(function () use ($started, $finished): void {
            $this->startedAt = $started;
            $this->finishedAt = $finished;
        }, $session, PracticeSession::class))();

        return $session;
    }

    /**
     * A "zäh"/"wird besser" rated session - STATUS_STARTED, not a genuine
     * completion (see PracticeSession::finish()).
     */
    private function startedSession(Exercise $exercise, \DateTimeImmutable $date, int $minutes): PracticeSession
    {
        $session = new PracticeSession('visitor-1', 'session-'.uniqid(), null, $exercise);

        $started = $date->setTime(9, 0);
        $finished = $started->modify("+{$minutes} minutes");

        (\Closure::bind(function () use ($started, $finished): void {
            $this->startedAt = $started;
            $this->finishedAt = $finished;
            $this->status = PracticeSession::STATUS_STARTED;
            $this->learningState = PracticeSession::LEARNING_STATE_DIFFICULT;
        }, $session, PracticeSession::class))();

        return $session;
    }

    private function runningSession(Exercise $exercise, int $id): PracticeSession
    {
        $session = new PracticeSession('visitor-1', 'session-'.uniqid(), null, $exercise);
        (\Closure::bind(function () use ($id): void {
            $this->id = $id;
        }, $session, PracticeSession::class))();

        return $session;
    }
}
