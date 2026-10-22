<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Entity\Exercise;
use App\Entity\ExerciseVersion;
use App\Entity\User;
use App\Repository\DayNoteRepository;
use App\Repository\ExerciseGroupRepository;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use App\Repository\RoutineRepository;

/**
 * Everything personal data this app holds for one account, as a plain
 * array ready for json_encode() - the DSGVO/GDPR "export my data" button
 * (Art. 15/20: a complete, structured, machine-readable dump, not a
 * curated report). Scoped by visitorId, same as every other per-visitor
 * query in the app - a User only ever "claims" one, see CLAUDE.md.
 */
final class AccountDataExporter
{
    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly ExerciseVersionRepository $versions,
        private readonly ExerciseResourceRepository $resources,
        private readonly ExerciseGroupRepository $groups,
        private readonly RoutineRepository $routines,
        private readonly PracticeSessionRepository $sessions,
        private readonly DayNoteRepository $dayNotes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $visitorId = $user->getVisitorId();

        $versionsByExerciseId = [];
        foreach ($this->versions->forVisitor($visitorId) as $version) {
            $versionsByExerciseId[$version->getExercise()->getId()][] = $version;
        }

        return [
            'account' => [
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'mailPreferences' => [
                    'streakRemindersEnabled' => !$user->hasOptedOutOfStreakReminders(),
                    'dailyDigestEnabled' => $user->hasDailyDigestEnabled(),
                ],
            ],
            'exerciseGroups' => array_map(static fn ($g) => [
                'id' => $g->getId(),
                'name' => $g->getName(),
                'kind' => $g->getKind(),
                'createdAt' => $g->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $this->groups->forVisitor($visitorId)),
            'exercises' => array_map(
                fn (Exercise $e) => $this->exerciseToArray($e, $versionsByExerciseId[$e->getId()] ?? []),
                $this->exercises->forVisitor($visitorId),
            ),
            'routines' => array_map(static fn ($r) => [
                'id' => $r->getId(),
                'name' => $r->getName(),
                'isActive' => $r->isActive(),
                'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'items' => array_map(static fn ($i) => [
                    'exerciseId' => $i->getExercise()->getId(),
                    'sortIndex' => $i->getSortIndex(),
                ], $r->getItems()->toArray()),
            ], $this->routines->forVisitor($visitorId)),
            'dayNotes' => array_map(static fn ($n) => [
                'date' => $n->getDate()->format('Y-m-d'),
                'note' => $n->getNote(),
            ], $this->dayNotes->forVisitor($visitorId)),
            'practiceSessions' => array_map(static fn ($s) => [
                'id' => $s->getId(),
                'tool' => $s->getTool(),
                'exerciseId' => $s->getExercise()?->getId(),
                'status' => $s->getStatus(),
                'startedAt' => $s->getStartedAt()->format(\DateTimeInterface::ATOM),
                'finishedAt' => $s->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'totalQuestions' => $s->getTotalQuestions(),
                'correctCount' => $s->getCorrectCount(),
                'learningState' => $s->getLearningState(),
                'note' => $s->getNote(),
            ], $this->sessions->forVisitor($visitorId, PHP_INT_MAX)),
        ];
    }

    /**
     * @param ExerciseVersion[] $exerciseVersions
     */
    private function exerciseToArray(Exercise $exercise, array $exerciseVersions): array
    {
        return [
            'id' => $exercise->getId(),
            'note' => $exercise->getNote(),
            'archivedAt' => $exercise->getArchivedAt()?->format('Y-m-d'),
            'deactivatedAt' => $exercise->getDeactivatedAt()?->format('Y-m-d'),
            'createdAt' => $exercise->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'versions' => array_map(static fn (ExerciseVersion $v) => [
                'title' => $v->getTitle(),
                'group' => $v->getGroup()?->getName(),
                'targetMinutes' => $v->getTargetMinutes(),
                'repeatRhythm' => $v->getRepeatRhythm(),
                'repeatCount' => $v->getRepeatCount(),
                'validFrom' => $v->getValidFrom()->format('Y-m-d'),
                'validUntil' => $v->getValidUntil()?->format('Y-m-d'),
            ], $exerciseVersions),
            'resources' => array_map(static fn ($r) => [
                'type' => $r->getType(),
                'internalToolId' => $r->getInternalToolId(),
                'externalUrl' => $r->getExternalUrl(),
                'externalTitle' => $r->getExternalTitle(),
            ], $this->resources->forExercise($exercise)),
        ];
    }
}
