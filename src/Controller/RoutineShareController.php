<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\ExerciseGroupProvisioner;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\ExerciseVersion;
use App\Entity\Routine;
use App\Entity\RoutineItem;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\RoutineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishing and importing a Routine via a public link - deliberately
 * separate from RoutineController, which only ever deals with a routine
 * the current visitor already owns. A shared link is a snapshot at import
 * time, not a live sync: cloning creates entirely new Exercise/
 * ExerciseVersion/ExerciseResource rows for the importing visitor, so
 * later edits on either side never affect the other, exactly like any
 * exercise someone added themselves.
 *
 * Importing is login-gated on purpose - an anonymous visitor's cookie can
 * be lost at any time (cleared cookies, a new browser), which would
 * silently orphan an imported routine with no way back; a logged-in
 * account is the durable identity worth attaching a copy to.
 */
final class RoutineShareController extends AbstractController
{
    private const CSRF_INTENTION = 'routine';

    public function __construct(
        private readonly ExerciseGroupProvisioner $groupProvisioner,
    ) {
    }

    #[Route('/routinen/{id}/teilen', name: 'routine_share', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function share(int $id, Request $request, RoutineRepository $routines, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $routine = $routines->findForVisitor($id, $this->requireVisitorId($request));
        if ($routine !== null) {
            $routine->ensureShareToken();
            $em->flush();
        }

        return $this->redirectToRoute('routine_show', ['id' => $id]);
    }

    #[Route('/r/{token}', name: 'routine_shared_show', methods: ['GET'])]
    public function show(string $token, Request $request, RoutineRepository $routines, ExerciseVersionRepository $versions): Response
    {
        $routine = $routines->findByShareToken($token);
        if ($routine === null) {
            return $this->redirectToRoute('home_index');
        }

        $today = new \DateTimeImmutable('today');
        $items = [];
        foreach ($routine->getItems() as $item) {
            $version = $versions->currentFor($item->getExercise(), $today);
            if ($version !== null) {
                $items[] = $version;
            }
        }

        // Shown before the click, not just after - so re-visiting an
        // already-imported link doesn't look like nothing would happen.
        $alreadyImported = $this->getUser() !== null
            ? $routines->findImportedFrom($this->requireVisitorId($request), $routine)
            : null;

        return $this->render('routine/shared.html.twig', [
            'routine' => $routine,
            'versions' => $items,
            'token' => $token,
            'alreadyImportedRoutineId' => $alreadyImported?->getId(),
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    /**
     * Reuses a previously-imported copy (routine and/or individual
     * exercises, tracked via markImportedFrom()) rather than blindly
     * re-cloning - re-clicking "Übernehmen" on the same link, or on a
     * routine that shares some exercises with one already imported
     * before, never creates duplicates.
     */
    #[Route('/r/{token}/uebernehmen', name: 'routine_shared_import', methods: ['POST'])]
    public function import(
        string $token,
        Request $request,
        RoutineRepository $routines,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $sourceRoutine = $routines->findByShareToken($token);
        if ($sourceRoutine === null) {
            return $this->redirectToRoute('home_index');
        }

        if ($this->getUser() === null) {
            return $this->redirectToRoute('app_login');
        }

        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');

        $targetRoutine = $routines->findImportedFrom($visitorId, $sourceRoutine);
        $isNewRoutine = $targetRoutine === null;
        if ($targetRoutine === null) {
            $targetRoutine = new Routine($visitorId, $sourceRoutine->getName(), $routines->countForVisitor($visitorId));
            $targetRoutine->markImportedFrom($sourceRoutine);
            $em->persist($targetRoutine);
        }

        $existingExerciseIds = [];
        $maxSortIndex = -1;
        foreach ($targetRoutine->getItems() as $existingItem) {
            $existingExerciseIds[] = $existingItem->getExercise()->getId();
            $maxSortIndex = max($maxSortIndex, $existingItem->getSortIndex());
        }

        $addedAny = false;
        foreach ($sourceRoutine->getItems() as $item) {
            $sourceExercise = $item->getExercise();
            $version = $versions->currentFor($sourceExercise, $today);
            if ($version === null) {
                continue;
            }

            $targetExercise = $exercises->findImportedFrom($visitorId, $sourceExercise);
            if ($targetExercise === null) {
                $targetExercise = $this->cloneExercise($visitorId, $sourceExercise, $version, $resources, $em, $today);
            }

            if (in_array($targetExercise->getId(), $existingExerciseIds, true)) {
                continue; // already in this routine - nothing to add
            }

            $em->persist(new RoutineItem($targetRoutine, $targetExercise, ++$maxSortIndex));
            $addedAny = true;
        }

        $em->flush();

        if ($addedAny || $isNewRoutine) {
            $this->addFlash('success', 'routine.share.import_flash');
        } else {
            $this->addFlash('info', 'routine.share.nothing_new');
        }

        return $this->redirectToRoute('routine_show', ['id' => $targetRoutine->getId()]);
    }

    private function cloneExercise(
        string $visitorId,
        Exercise $sourceExercise,
        ExerciseVersion $sourceVersion,
        ExerciseResourceRepository $resources,
        EntityManagerInterface $em,
        \DateTimeImmutable $today,
    ): Exercise {
        $sourceResources = $resources->forExercise($sourceExercise);

        $newExercise = new Exercise($visitorId);
        $newExercise->markImportedFrom($sourceExercise);
        $em->persist($newExercise);

        $group = $this->groupProvisioner->systemGroupFor($visitorId, $this->hasInternalTool($sourceResources));
        $em->persist(new ExerciseVersion(
            $newExercise,
            $sourceVersion->getTitle(),
            $group,
            $sourceVersion->getTargetMinutes(),
            $sourceVersion->getRepeatRhythm(),
            $sourceVersion->getRepeatCount(),
            $today,
        ));

        foreach ($sourceResources as $sourceResource) {
            $em->persist($this->cloneResource($newExercise, $sourceResource));
        }

        return $newExercise;
    }

    /**
     * @param ExerciseResource[] $resources
     */
    private function hasInternalTool(array $resources): bool
    {
        foreach ($resources as $resource) {
            if ($resource->isInternalTool()) {
                return true;
            }
        }

        return false;
    }

    private function cloneResource(Exercise $newExercise, ExerciseResource $source): ExerciseResource
    {
        return $source->isInternalTool()
            ? ExerciseResource::internalTool($newExercise, $source->getSortIndex(), $source->getInternalToolId(), $source->getDefaultConfigSnapshot())
            : ExerciseResource::externalLink($newExercise, $source->getSortIndex(), $source->getExternalUrl(), $source->getExternalProvider(), $source->getExternalTitle());
    }

    private function requireVisitorId(Request $request): string
    {
        $v = $request->attributes->get('visitorId');

        return is_string($v) ? $v : '';
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
