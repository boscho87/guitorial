<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;

/**
 * Resolves the optional "start this tool round as a plan exercise" link
 * (?uebung=&ressource= on a tool's settings page) into the Exercise/
 * ExerciseResource pair a PracticeSession should be attributed to - or null
 * if the ids are missing, don't belong to this visitor, or don't actually
 * point at an internal_tool resource for this exact tool. Shared by all 5
 * tool controllers rather than duplicating this validation five times.
 */
final class ExercisePlanLink
{
    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly ExerciseResourceRepository $resources,
    ) {
    }

    /**
     * @return array{exercise: Exercise, resource: ExerciseResource}|null
     */
    public function resolve(string $visitorId, ?string $exerciseId, ?string $resourceId, string $tool): ?array
    {
        if ($visitorId === '' || $exerciseId === null || $exerciseId === '' || $resourceId === null || $resourceId === '') {
            return null;
        }

        $exercise = $this->exercises->findForVisitor((int) $exerciseId, $visitorId);
        if ($exercise === null) {
            return null;
        }

        foreach ($this->resources->forExercise($exercise) as $resource) {
            if (
                $resource->getId() === (int) $resourceId
                && $resource->isInternalTool()
                && $resource->getInternalToolId() === $tool
            ) {
                return ['exercise' => $exercise, 'resource' => $resource];
            }
        }

        return null;
    }
}
