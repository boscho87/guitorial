<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\ExerciseDayViewBuilder;
use App\Domain\Diary\RoutineProvisioner;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\Routine;
use App\Entity\RoutineItem;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use App\Repository\RoutineItemRepository;
use App\Repository\RoutineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manages named, ordered lists of existing exercises ("Routinen" tab of
 * Meine Übungen). Deliberately thin: a Routine only ever points at
 * Exercise rows that already exist and are managed by DiaryController -
 * "starten" reuses the exact same start/mark-done UI and the exact same
 * "done today" derivation (ExerciseDayViewBuilder) as the Heute checklist,
 * so there's no second completion-tracking model to keep in sync.
 */
#[Route('/routinen')]
final class RoutineController extends AbstractController
{
    private const CSRF_INTENTION = 'routine';
    private const NAME_MAX_LENGTH = 80;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ExerciseDayViewBuilder $dayViewBuilder,
        private readonly RoutineProvisioner $routineProvisioner,
    ) {
    }

    #[Route('', name: 'routine_index', methods: ['GET'])]
    public function index(Request $request, RoutineRepository $routines): Response
    {
        $visitorId = $this->requireVisitorId($request);
        if ($visitorId !== '') {
            // So visiting /routinen directly (without ever visiting Heute
            // first) also shows the auto-provisioned default routine
            // instead of an empty list.
            $this->routineProvisioner->ensureActiveRoutine($visitorId);
        }

        return $this->render('routine/index.html.twig', [
            'routines' => $visitorId !== '' ? $routines->forVisitor($visitorId) : [],
        ]);
    }

    #[Route('/hinzufuegen', name: 'routine_add_form', methods: ['GET'])]
    public function addForm(Request $request, ExerciseRepository $exercises, ExerciseVersionRepository $versions): Response
    {
        $visitorId = $this->requireVisitorId($request);

        return $this->render('routine/form.html.twig', [
            'routine' => null,
            'availableExercises' => $this->activeExercisesForVisitor($visitorId, $exercises, $versions),
            'selectedExerciseIds' => [],
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/hinzufuegen', name: 'routine_add', methods: ['POST'])]
    public function add(
        Request $request,
        ExerciseRepository $exercises,
        RoutineRepository $routines,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        if ($visitorId === '') {
            return $this->redirectToRoute('routine_add_form');
        }

        $name = mb_substr(trim((string) $request->request->get('name')), 0, self::NAME_MAX_LENGTH);
        if ($name === '') {
            return $this->redirectToRoute('routine_add_form');
        }

        $routine = new Routine($visitorId, $name, $routines->countForVisitor($visitorId));
        if ($routines->findActiveForVisitor($visitorId) === null) {
            // First routine ever (or the defensive "routines exist, none
            // active" case) - the invariant requires at least one active
            // routine whenever any exist.
            $routine->activate();
        }
        $em->persist($routine);

        $sortIndex = 0;
        foreach ($this->readExerciseIds($request) as $exerciseId) {
            $exercise = $exercises->findForVisitor($exerciseId, $visitorId);
            if ($exercise !== null) {
                $em->persist(new RoutineItem($routine, $exercise, $sortIndex++));
            }
        }

        $em->flush();

        return $this->redirectToRoute('routine_index');
    }

    #[Route('/{id}/bearbeiten', name: 'routine_edit_form', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editForm(
        int $id,
        Request $request,
        RoutineRepository $routines,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $routine = $routines->findForVisitor($id, $visitorId);
        if ($routine === null) {
            return $this->redirectToRoute('routine_index');
        }

        $today = new \DateTimeImmutable('today');
        $selectedIds = [];
        $itemTitles = [];
        foreach ($routine->getItems() as $item) {
            $selectedIds[] = $item->getExercise()->getId();
            $version = $versions->currentFor($item->getExercise(), $today);
            $itemTitles[$item->getId()] = $version?->getTitle() ?? '';
        }

        return $this->render('routine/form.html.twig', [
            'routine' => $routine,
            'itemTitles' => $itemTitles,
            'availableExercises' => $this->activeExercisesForVisitor($visitorId, $exercises, $versions),
            'selectedExerciseIds' => $selectedIds,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/{id}/bearbeiten', name: 'routine_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        RoutineRepository $routines,
        ExerciseRepository $exercises,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $routine = $routines->findForVisitor($id, $visitorId);
        if ($routine === null) {
            return $this->redirectToRoute('routine_index');
        }

        $name = mb_substr(trim((string) $request->request->get('name')), 0, self::NAME_MAX_LENGTH);
        if ($name !== '') {
            $routine->rename($name);
        }

        $checkedIds = $this->readExerciseIds($request);
        $existingByExerciseId = [];
        $maxSortIndex = -1;
        foreach ($routine->getItems() as $item) {
            $existingByExerciseId[$item->getExercise()->getId()] = $item;
            $maxSortIndex = max($maxSortIndex, $item->getSortIndex());
        }

        // Bulk add/remove via the checkbox list never touches the sortIndex
        // of items that stay checked - manual reordering (routine_item_move)
        // must survive an unrelated add/remove edit.
        foreach ($existingByExerciseId as $exerciseId => $item) {
            if (!in_array($exerciseId, $checkedIds, true)) {
                $routine->removeItem($item);
                $em->remove($item);
            }
        }
        foreach ($checkedIds as $exerciseId) {
            if (isset($existingByExerciseId[$exerciseId])) {
                continue;
            }
            $exercise = $exercises->findForVisitor($exerciseId, $visitorId);
            if ($exercise !== null) {
                $em->persist(new RoutineItem($routine, $exercise, ++$maxSortIndex));
            }
        }

        $em->flush();

        return $this->redirectToRoute('routine_index');
    }

    #[Route('/{id}/element/{itemId}/verschieben', name: 'routine_item_move', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function moveItem(
        int $id,
        int $itemId,
        Request $request,
        RoutineRepository $routines,
        RoutineItemRepository $items,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $routine = $routines->findForVisitor($id, $visitorId);
        $item = $items->find($itemId);
        $direction = (string) $request->request->get('direction');

        if ($routine !== null && $item !== null && $item->getRoutine() === $routine && in_array($direction, ['up', 'down'], true)) {
            $ordered = $routine->getItems()->toArray();
            $index = array_search($item, $ordered, true);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index !== false && $swapWith >= 0 && $swapWith < count($ordered)) {
                $a = $ordered[$index];
                $b = $ordered[$swapWith];
                $aSortIndex = $a->getSortIndex();
                $a->moveTo($b->getSortIndex());
                $b->moveTo($aSortIndex);
                $em->flush();
            }
        }

        return $this->redirectToRoute('routine_edit_form', ['id' => $id]);
    }

    #[Route('/{id}/loeschen', name: 'routine_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, RoutineRepository $routines, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $routine = $routines->findForVisitor($id, $this->requireVisitorId($request));
        if ($routine !== null) {
            // Deleting the active routine would leave Heute without one -
            // the UI never offers this button for it, this is just the
            // defense-in-depth backstop.
            if ($routine->isActive()) {
                // frame_toast, not the usual error/success buckets - the
                // delete form lives inside the routine-list turbo-frame, so
                // this redirect is frame-scoped and never reaches
                // base.html.twig's own flash block at all (see
                // _frame_toast.html.twig for why this needs its own bucket).
                $this->addFlash('frame_toast', 'routine.delete_active_error');

                return $this->redirectToRoute('routine_index');
            }

            $em->remove($routine);
            $em->flush();
            $this->addFlash('frame_toast', 'routine.delete_flash');
        }

        return $this->redirectToRoute('routine_index');
    }

    /**
     * Marks this routine as the one that determines what "Heute" shows -
     * deactivates any other currently-active routine first (at most one
     * active per visitor, enforced here rather than at the DB level).
     * Redirects straight to Heute so the effect is immediately visible.
     */
    #[Route('/{id}/aktivieren', name: 'routine_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(int $id, Request $request, RoutineRepository $routines, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $routine = $routines->findForVisitor($id, $visitorId);
        if ($routine !== null) {
            $currentlyActive = $routines->findActiveForVisitor($visitorId);
            if ($currentlyActive !== null && $currentlyActive !== $routine) {
                $currentlyActive->deactivate();
            }
            $routine->activate();
            $em->flush();
        }

        return $this->redirectToRoute('diary_index');
    }

    #[Route('/{id}', name: 'routine_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        Request $request,
        RoutineRepository $routines,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        PracticeSessionRepository $sessions,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $routine = $routines->findForVisitor($id, $visitorId);
        if ($routine === null) {
            return $this->redirectToRoute('routine_index');
        }

        $today = new \DateTimeImmutable('today');
        $allVersions = $versions->forVisitor($visitorId);
        $allSessions = $sessions->forVisitor($visitorId, 300);
        $rows = $this->dayViewBuilder->buildDayForRoutine($routine, $allVersions, $allSessions, $today);

        $resourcesByExerciseId = [];
        foreach ($rows as $row) {
            $exercise = $row['version']->getExercise();
            $resourcesByExerciseId[$exercise->getId()] = $resources->forExercise($exercise);
        }

        $toolTitles = [];
        $toolSettingsRoutes = [];
        $toolPracticeRoutes = [];
        foreach ($this->tools->all() as $tool) {
            $toolTitles[$tool['id']] = $tool['title'];
            $toolSettingsRoutes[$tool['id']] = $tool['route'];
            $toolPracticeRoutes[$tool['id']] = str_replace('_settings', '_practice', $tool['route']);
        }

        return $this->render('routine/show.html.twig', [
            'routine' => $routine,
            'rows' => $rows,
            'resourcesByExerciseId' => $resourcesByExerciseId,
            'toolTitles' => $toolTitles,
            'toolSettingsRoutes' => $toolSettingsRoutes,
            'toolPracticeRoutes' => $toolPracticeRoutes,
            'allDone' => $rows !== [] && array_reduce($rows, static fn (bool $carry, array $r): bool => $carry && $r['checked'], true),
            'csrfIntention' => self::CSRF_INTENTION,
            'sessionCsrfIntention' => 'exercise_session',
        ]);
    }

    /**
     * @return array<int, array{exercise: \App\Entity\Exercise, version: \App\Entity\ExerciseVersion}> sorted by title
     */
    private function activeExercisesForVisitor(string $visitorId, ExerciseRepository $exercises, ExerciseVersionRepository $versions): array
    {
        if ($visitorId === '') {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $entries = [];
        foreach ($exercises->forVisitor($visitorId) as $exercise) {
            if (!$exercise->isActiveOn($today)) {
                continue;
            }
            $version = $versions->currentFor($exercise, $today);
            if ($version !== null) {
                $entries[] = ['exercise' => $exercise, 'version' => $version];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['version']->getTitle() <=> $b['version']->getTitle());

        return $entries;
    }

    /**
     * @return int[]
     */
    private function readExerciseIds(Request $request): array
    {
        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $request->request->all('exercise_ids'),
        )));
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
