<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\Exercise;
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
            // Also registered on the in-memory collection: the caller renders
            // this very request from $routine->getItems(), and without it the
            // freshly created routine looked empty until the next page load
            // (a newcomer's first Heute after saving their first exercise).
            $item = new RoutineItem($routine, $entry['exercise'], $sortIndex);
            $routine->addItem($item);
            $this->em->persist($item);
        }

        $this->em->flush();

        return $routine;
    }

    /**
     * A newly created exercise joins the end of the active routine, so it
     * shows up on Heute right away instead of only after being added by
     * hand in the routine editor (Heute lists the active routine's
     * members, not every exercise). Without an active routine there is
     * nothing to append to: ensureActiveRoutine() builds one from all
     * active exercises - this one included - the next time it's needed.
     * Persists only; the caller's flush() writes it.
     */
    public function appendToActiveRoutine(string $visitorId, Exercise $exercise): void
    {
        $routine = $this->routines->findActiveForVisitor($visitorId);
        if ($routine === null) {
            return;
        }

        $this->appendToRoutine($routine, $exercise);
    }

    /**
     * Same append as appendToActiveRoutine(), for a caller that already
     * has a specific (possibly non-active) Routine in hand - e.g. the
     * "neue Übung direkt hier hinzufügen" quick-add on the routine editor,
     * which must land the new exercise in *that* routine regardless of
     * which one is active, not silently also show up on Heute.
     */
    public function appendToRoutine(Routine $routine, Exercise $exercise): void
    {
        $nextSortIndex = 0;
        foreach ($routine->getItems() as $item) {
            $nextSortIndex = max($nextSortIndex, $item->getSortIndex() + 1);
        }

        $item = new RoutineItem($routine, $exercise, $nextSortIndex);
        $routine->addItem($item);
        $this->em->persist($item);
    }
}
