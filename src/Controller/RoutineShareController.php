<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\ExerciseGroupProvisioner;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\ExerciseVersion;
use App\Entity\Routine;
use App\Entity\RoutineItem;
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
    public function show(string $token, RoutineRepository $routines, ExerciseVersionRepository $versions): Response
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

        return $this->render('routine/shared.html.twig', [
            'routine' => $routine,
            'versions' => $items,
            'token' => $token,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/r/{token}/uebernehmen', name: 'routine_shared_import', methods: ['POST'])]
    public function import(
        string $token,
        Request $request,
        RoutineRepository $routines,
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

        $newRoutine = new Routine($visitorId, $sourceRoutine->getName(), $routines->countForVisitor($visitorId));
        $em->persist($newRoutine);

        $sortIndex = 0;
        foreach ($sourceRoutine->getItems() as $item) {
            $version = $versions->currentFor($item->getExercise(), $today);
            if ($version === null) {
                continue;
            }

            $sourceResources = $resources->forExercise($item->getExercise());

            $newExercise = new Exercise($visitorId);
            $em->persist($newExercise);

            $group = $this->groupProvisioner->systemGroupFor($visitorId, $this->hasInternalTool($sourceResources));
            $em->persist(new ExerciseVersion(
                $newExercise,
                $version->getTitle(),
                $group,
                $version->getTargetMinutes(),
                $version->getRepeatRhythm(),
                $version->getRepeatCount(),
                $today,
            ));

            foreach ($sourceResources as $sourceResource) {
                $em->persist($this->cloneResource($newExercise, $sourceResource));
            }

            $em->persist(new RoutineItem($newRoutine, $newExercise, $sortIndex++));
        }

        $em->flush();

        $this->addFlash('success', 'routine.share.import_flash');

        return $this->redirectToRoute('routine_show', ['id' => $newRoutine->getId()]);
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
