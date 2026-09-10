<?php

declare(strict_types=1);

namespace App\Domain\History;

use App\Entity\ExerciseGroup;
use App\Entity\ExerciseVersion;
use App\Entity\PracticeSession;

/**
 * Aggregates a visitor's practice history by exercise GROUP, never by
 * individual exercise - a deliberate, binding decision (see the planning
 * doc's addendum): /verlauf/gruppen shows only which groups get practiced
 * and how it went, not a per-exercise list. Individual detail lives
 * exclusively at /verlauf/uebung/{id} instead.
 *
 * A session is bucketed by its exercise's CURRENT group, not whichever
 * group was active when the session happened - re-grouping an exercise
 * re-buckets its whole history under the new group, which is the more
 * useful reading ("this is now Repertoire, so its history lives there")
 * rather than fragmenting stats across former group names.
 */
final class HistoryGroupStatsBuilder
{
    private const WINDOW_DAYS = 30;

    /**
     * @param PracticeSession[] $sessions this visitor's sessions, any status
     * @param ExerciseVersion[] $currentVersions one CURRENT version per exercise (today-active)
     * @param ExerciseGroup[] $groups ordered by sortIndex
     * @return list<array{group: ?ExerciseGroup, practicedMinutes: int, sessionCount: int, learningStates: array<string, int>, exerciseCount: int}>
     */
    public function build(array $sessions, array $currentVersions, array $groups, \DateTimeImmutable $today): array
    {
        $groupIdByExerciseId = [];
        $exerciseCountByGroupId = [];
        foreach ($currentVersions as $version) {
            $exerciseId = $version->getExercise()->getId();
            $groupId = $version->getGroup()?->getId() ?? 0;
            $groupIdByExerciseId[$exerciseId] = $groupId;
            $exerciseCountByGroupId[$groupId] = ($exerciseCountByGroupId[$groupId] ?? 0) + 1;
        }

        $windowStart = $today->modify('-'.self::WINDOW_DAYS.' days');
        $stats = [];
        foreach ($sessions as $s) {
            $exercise = $s->getExercise();
            if ($exercise === null || !$s->isFinished() || $s->getFinishedAt() < $windowStart) {
                continue;
            }

            $groupId = $groupIdByExerciseId[$exercise->getId()] ?? 0;
            $stats[$groupId]['minutes'] = ($stats[$groupId]['minutes'] ?? 0.0)
                + ($s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp()) / 60;
            $stats[$groupId]['count'] = ($stats[$groupId]['count'] ?? 0) + 1;

            $learningState = $s->getLearningState() ?? 'unbewertet';
            $stats[$groupId]['learningStates'][$learningState] = ($stats[$groupId]['learningStates'][$learningState] ?? 0) + 1;
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = $this->row($group, $stats[$group->getId()] ?? null, $exerciseCountByGroupId[$group->getId()] ?? 0);
        }
        if (isset($stats[0]) || isset($exerciseCountByGroupId[0])) {
            $rows[] = $this->row(null, $stats[0] ?? null, $exerciseCountByGroupId[0] ?? 0);
        }

        return $rows;
    }

    /**
     * @param array{minutes?: float, count?: int, learningStates?: array<string, int>}|null $stat
     * @return array{group: ?ExerciseGroup, practicedMinutes: int, sessionCount: int, learningStates: array<string, int>, exerciseCount: int}
     */
    private function row(?ExerciseGroup $group, ?array $stat, int $exerciseCount): array
    {
        return [
            'group' => $group,
            'practicedMinutes' => (int) round($stat['minutes'] ?? 0.0),
            'sessionCount' => $stat['count'] ?? 0,
            'learningStates' => $stat['learningStates'] ?? [],
            'exerciseCount' => $exerciseCount,
        ];
    }
}
