<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\Routine;
use App\Entity\RoutineItem;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\RoutineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Get-or-create for a visitor's active Routine - Heute is always
 * routine-driven, so a visitor with at least one exercise can never
 * legitimately have zero active routines. Lazily converts "Heute" (every
 * active exercise, in the same order the old grouped fallback used) into a
 * first Routine the moment it's needed, mirroring ExerciseGroupProvisioner's
 * get-or-create shape rather than a one-off data migration - the selection
 * of "which exercises are active today" already lives in
 * Exercise::isActiveOn()/ExerciseVersionRepository::currentFor(), no need to
 * duplicate that in raw SQL.
 */
final class RoutineProvisioner
{
    public function __construct(
        private readonly RoutineRepository $routines,
        private readonly ExerciseRepository $exercises,
        private readonly ExerciseVersionRepository $versions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureActiveRoutine(string $visitorId): ?Routine
    {
        $active = $this->routines->findActiveForVisitor($visitorId);
        if ($active !== null) {
            return $active;
        }

        if ($this->routines->countForVisitor($visitorId) > 0) {
            // Routines exist, none active - not reachable through the UI
            // (activate()/delete() keep the invariant), but as a safety
            // net, promote the first one rather than leaving Heute empty.
            $fallback = $this->routines->forVisitor($visitorId)[0] ?? null;
            if ($fallback !== null) {
                $fallback->activate();
                $this->em->flush();
            }

            return $fallback;
        }

        $today = new \DateTimeImmutable('today');
        $entries = [];
        foreach ($this->exercises->forVisitor($visitorId) as $exercise) {
            if (!$exercise->isActiveOn($today)) {
                continue;
            }
            $version = $this->versions->currentFor($exercise, $today);
            if ($version !== null) {
                $entries[] = ['exercise' => $exercise, 'version' => $version];
            }
        }

        if ($entries === []) {
            return null;
        }

        usort($entries, static fn (array $a, array $b): int => [
            $a['version']->getGroup()?->getSortIndex() ?? \PHP_INT_MAX, $a['version']->getTitle(),
        ] <=> [
            $b['version']->getGroup()?->getSortIndex() ?? \PHP_INT_MAX, $b['version']->getTitle(),
        ]);

        $routine = new Routine($visitorId, 'Meine Routine', 0);
        $routine->activate();
        $this->em->persist($routine);

        foreach ($entries as $sortIndex => $entry) {
            $this->em->persist(new RoutineItem($routine, $entry['exercise'], $sortIndex));
        }

        $this->em->flush();

        return $routine;
    }
}
