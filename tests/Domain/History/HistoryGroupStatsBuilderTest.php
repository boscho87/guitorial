<?php

declare(strict_types=1);

namespace App\Tests\Domain\History;

use App\Domain\History\HistoryGroupStatsBuilder;
use App\Entity\Exercise;
use App\Entity\ExerciseGroup;
use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;
use PHPUnit\Framework\TestCase;

final class HistoryGroupStatsBuilderTest extends TestCase
{
    private HistoryGroupStatsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HistoryGroupStatsBuilder();
    }

    /**
     * The binding requirement from the planning doc's addendum: this
     * aggregation must never expose an individual exercise identity, only
     * group-level numbers - verified structurally, not just by convention.
     */
    public function testRowsNeverExposeIndividualExerciseIdentity(): void
    {
        $today = new \DateTimeImmutable('today');
        $group = $this->group(1, 'Repertoire');
        $exercise = $this->exercise(1, $group, $today->modify('-60 days'));
        $sessions = [$this->finishedSession($exercise, $today, 10, 'sicher')];

        $rows = $this->builder->build($sessions, [$exercise['version']], [$group], $today);

        foreach ($rows as $row) {
            self::assertArrayNotHasKey('exercise', $row);
            self::assertArrayNotHasKey('exercises', $row);
            self::assertArrayNotHasKey('title', $row);
        }
    }

    public function testMinutesAndCountAggregateAcrossExercisesInTheSameGroup(): void
    {
        $today = new \DateTimeImmutable('today');
        $group = $this->group(1, 'Theorie');
        $a = $this->exercise(1, $group, $today->modify('-60 days'));
        $b = $this->exercise(2, $group, $today->modify('-60 days'));

        $sessions = [
            $this->finishedSession($a, $today, 10, null),
            $this->finishedSession($b, $today, 5, null),
        ];

        $rows = $this->builder->build($sessions, [$a['version'], $b['version']], [$group], $today);

        self::assertCount(1, $rows);
        self::assertSame(15, $rows[0]['practicedMinutes']);
        self::assertSame(2, $rows[0]['sessionCount']);
        self::assertSame(2, $rows[0]['exerciseCount']);
    }

    public function testSessionsOlderThanTheWindowAreExcluded(): void
    {
        $today = new \DateTimeImmutable('today');
        $group = $this->group(1, 'Praxis');
        $exercise = $this->exercise(1, $group, $today->modify('-60 days'));

        $sessions = [$this->finishedSession($exercise, $today->modify('-40 days'), 10, null)];

        $rows = $this->builder->build($sessions, [$exercise['version']], [$group], $today);

        self::assertSame(0, $rows[0]['sessionCount']);
        self::assertSame(0, $rows[0]['practicedMinutes']);
    }

    public function testUngroupedExercisesBucketSeparatelyAsNull(): void
    {
        $today = new \DateTimeImmutable('today');
        $exercise = $this->exercise(1, null, $today->modify('-10 days'));

        $rows = $this->builder->build([$this->finishedSession($exercise, $today, 10, null)], [$exercise['version']], [], $today);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['group']);
        self::assertSame(10, $rows[0]['practicedMinutes']);
    }

    public function testLearningStateDistributionCountsEachRating(): void
    {
        $today = new \DateTimeImmutable('today');
        $group = $this->group(1, 'Theorie');
        $exercise = $this->exercise(1, $group, $today->modify('-10 days'));

        $sessions = [
            $this->finishedSession($exercise, $today, 5, 'sicher'),
            $this->finishedSession($exercise, $today, 5, 'sicher'),
            $this->finishedSession($exercise, $today, 5, 'schwierig'),
            $this->finishedSession($exercise, $today, 5, null),
        ];

        $rows = $this->builder->build($sessions, [$exercise['version']], [$group], $today);

        self::assertSame(2, $rows[0]['learningStates']['sicher']);
        self::assertSame(1, $rows[0]['learningStates']['schwierig']);
        self::assertSame(1, $rows[0]['learningStates']['unbewertet']);
    }

    /**
     * @return array{exercise: Exercise, version: ExerciseVersion}
     */
    private function exercise(int $id, ?ExerciseGroup $group, \DateTimeImmutable $validFrom): array
    {
        $exercise = new Exercise('visitor-1');
        (\Closure::bind(function () use ($id): void {
            $this->id = $id;
        }, $exercise, Exercise::class))();

        $version = new ExerciseVersion($exercise, 'Übung '.$id, $group, null, ExerciseVersion::RHYTHM_NONE, null, $validFrom);

        return ['exercise' => $exercise, 'version' => $version];
    }

    private function group(int $id, string $name): ExerciseGroup
    {
        $group = new ExerciseGroup('visitor-1', $name, 0);
        (\Closure::bind(function () use ($id): void {
            $this->id = $id;
        }, $group, ExerciseGroup::class))();

        return $group;
    }

    /**
     * @param array{exercise: Exercise, version: ExerciseVersion} $exercise
     */
    private function finishedSession(array $exercise, \DateTimeImmutable $date, int $minutes, ?string $learningState): PracticeSession
    {
        $session = new PracticeSession('visitor-1', 'session-'.uniqid(), null, $exercise['exercise']);
        $session->applySync(questionIndex: 0, totalQuestions: null, correctCount: null, finished: true);

        $started = $date->setTime(10, 0);
        $finished = $started->modify("+{$minutes} minutes");

        (\Closure::bind(function () use ($started, $finished, $learningState): void {
            $this->startedAt = $started;
            $this->finishedAt = $finished;
            $this->learningState = $learningState;
        }, $session, PracticeSession::class))();

        return $session;
    }
}
