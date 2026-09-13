<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\DiarySuggestions;
use App\Domain\Diary\ExerciseDayViewBuilder;
use App\Domain\Diary\ExerciseGroupProvisioner;
use App\Domain\Diary\NoteLinkExtractor;
use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Domain\Session\SessionRecorder;
use App\Entity\Exercise;
use App\Entity\ExerciseGroup;
use App\Entity\ExerciseResource;
use App\Entity\ExerciseVersion;
use App\Repository\ExerciseGroupRepository;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manages the plan (groups + exercises + resources) and renders the daily
 * checklist built from it. Starting/running/finishing a session lives in
 * ExerciseSessionController instead - this controller only ever touches
 * Exercise/ExerciseVersion/ExerciseGroup/ExerciseResource, never
 * PracticeSession directly (except the one-tap "als erledigt markieren").
 */
#[Route('/tagebuch')]
final class DiaryController extends AbstractController
{
    private const CSRF_INTENTION = 'diary';
    private const RECENT_DAYS = 7;
    private const TITLE_MAX_LENGTH = 120;
    private const NOTE_MAX_LENGTH = 2000;
    private const GROUP_NAME_MAX_LENGTH = 40;
    private const MAX_MINUTES = 180;
    private const MAX_REPEAT_COUNT = 14;
    private const MAX_EXTERNAL_URL_LENGTH = 2048;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly DiarySuggestions $suggestions,
        private readonly ExerciseDayViewBuilder $dayViewBuilder,
        private readonly HistoryStatsBuilder $statsBuilder,
        private readonly ExerciseGroupProvisioner $groupProvisioner,
    ) {
    }

    #[Route('', name: 'diary_index', methods: ['GET'])]
    public function index(
        Request $request,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        ExerciseGroupRepository $groups,
        PracticeSessionRepository $sessions,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');
        $viewDate = $this->readViewDate($request, $today);
        $isToday = $viewDate->format('Y-m-d') === $today->format('Y-m-d');

        $allVersions = $visitorId !== '' ? $versions->forVisitor($visitorId) : [];
        $allSessions = $visitorId !== '' ? $sessions->forVisitor($visitorId, 300) : [];

        $rows = $this->dayViewBuilder->buildDay($allVersions, $allSessions, $viewDate);
        $recentRatios = $this->dayViewBuilder->buildRecentRatios($allVersions, $allSessions, $today, self::RECENT_DAYS);

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

        return $this->render('diary/index.html.twig', [
            'viewDate' => $viewDate,
            'isToday' => $isToday,
            'previousDate' => $viewDate->modify('-1 day'),
            'nextDate' => $isToday ? null : $viewDate->modify('+1 day'),
            'grouped' => $this->groupRows($rows, $visitorId !== '' ? $groups->forVisitor($visitorId) : []),
            'resourcesByExerciseId' => $resourcesByExerciseId,
            'toolTitles' => $toolTitles,
            'toolSettingsRoutes' => $toolSettingsRoutes,
            'toolPracticeRoutes' => $toolPracticeRoutes,
            'recentRatios' => $recentRatios,
            'currentStreak' => $this->statsBuilder->build($allSessions)['currentStreak'],
            'csrfIntention' => self::CSRF_INTENTION,
            'sessionCsrfIntention' => 'exercise_session',
        ]);
    }

    #[Route('/verwalten', name: 'diary_manage', methods: ['GET'])]
    public function manage(
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        ExerciseGroupRepository $groups,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');

        $active = [];
        $archived = [];
        foreach ($visitorId !== '' ? $exercises->forVisitor($visitorId) : [] as $exercise) {
            $currentVersion = $versions->currentFor($exercise, $today);
            if ($currentVersion === null) {
                continue;
            }

            $entry = ['exercise' => $exercise, 'version' => $currentVersion, 'resources' => $resources->forExercise($exercise)];
            if ($exercise->isActiveOn($today)) {
                $active[] = $entry;
            } else {
                $archived[] = $entry;
            }
        }

        return $this->render('diary/manage.html.twig', [
            'activeExercises' => $active,
            'archivedExercises' => $archived,
            'groups' => $groups->forVisitor($visitorId),
            'rhythms' => ExerciseVersion::RHYTHMS,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/gruppen', name: 'diary_groups', methods: ['GET'])]
    public function groupsManage(Request $request, ExerciseGroupRepository $groups): Response
    {
        $visitorId = $this->requireVisitorId($request);

        return $this->render('diary/groups.html.twig', [
            'groups' => $visitorId !== '' ? $groups->forVisitor($visitorId) : [],
            'maxGroups' => ExerciseGroupRepository::MAX_GROUPS_PER_VISITOR,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/gruppen/hinzufuegen', name: 'diary_group_add', methods: ['POST'])]
    public function addGroup(Request $request, ExerciseGroupRepository $groups, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $name = mb_substr(trim((string) $request->request->get('name')), 0, self::GROUP_NAME_MAX_LENGTH);

        if ($visitorId !== '' && $name !== '') {
            $customCount = $groups->countCustomForVisitor($visitorId);
            if ($customCount < ExerciseGroupRepository::MAX_GROUPS_PER_VISITOR) {
                $em->persist(new ExerciseGroup($visitorId, $name, $groups->countForVisitor($visitorId)));
                $em->flush();
            }
        }

        return $this->redirectToRoute('diary_groups');
    }

    #[Route('/gruppen/{id}/umbenennen', name: 'diary_group_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renameGroup(int $id, Request $request, ExerciseGroupRepository $groups, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $group = $groups->findForVisitor($id, $this->requireVisitorId($request));
        $name = mb_substr(trim((string) $request->request->get('name')), 0, self::GROUP_NAME_MAX_LENGTH);

        if ($group !== null && $name !== '') {
            $group->rename($name);
            $em->flush();
        }

        return $this->redirectToRoute('diary_groups');
    }

    #[Route('/gruppen/{id}/verschieben', name: 'diary_group_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function moveGroup(int $id, Request $request, ExerciseGroupRepository $groups, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $group = $groups->findForVisitor($id, $visitorId);
        $direction = (string) $request->request->get('direction');

        if ($group !== null && in_array($direction, ['up', 'down'], true)) {
            $all = $groups->forVisitor($visitorId);
            $index = array_search($group, $all, true);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index !== false && $swapWith >= 0 && $swapWith < count($all)) {
                $a = $all[$index];
                $b = $all[$swapWith];
                $aSortIndex = $a->getSortIndex();
                $a->moveTo($b->getSortIndex());
                $b->moveTo($aSortIndex);
                $em->flush();
            }
        }

        return $this->redirectToRoute('diary_groups');
    }

    #[Route('/gruppen/{id}/entfernen', name: 'diary_group_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeGroup(
        int $id,
        Request $request,
        ExerciseGroupRepository $groups,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $group = $groups->findForVisitor($id, $visitorId);

        if ($group !== null) {
            // "Keine Gruppe" isn't a state an exercise can fall back to, so
            // every exercise currently in this group gets reassigned to its
            // Theorie/Praxis system group instead - never left dangling.
            $affected = $versions->forGroup($group);
            $isToolLinked = array_map(
                fn (ExerciseVersion $v): bool => $this->hasInternalToolResource($resources->forExercise($v->getExercise())),
                $affected,
            );

            // Remove (and flush) first so the fallback lookup below can never
            // resolve back to the very group being deleted - relevant when
            // deleting a system group itself, which just gets recreated.
            $em->remove($group);
            $em->flush();

            foreach ($affected as $index => $version) {
                $version->reassignGroup($this->groupProvisioner->systemGroupFor($visitorId, $isToolLinked[$index]));
            }
            $em->flush();
        }

        return $this->redirectToRoute('diary_groups');
    }

    #[Route('/hinzufuegen', name: 'diary_add_form', methods: ['GET'])]
    public function addForm(): Response
    {
        return $this->render('diary/add.html.twig', [
            'suggestions' => $this->suggestions->all(),
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/hinzufuegen', name: 'diary_add', methods: ['POST'])]
    public function add(Request $request, ExerciseGroupRepository $groups, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        if ($visitorId === '') {
            return $this->redirectToRoute('diary_add_form');
        }

        $input = $this->readExerciseInput($request, $groups, $visitorId);
        if ($input['title'] === '') {
            return $this->redirectToRoute('diary_add_form');
        }

        $resourceInputs = $this->readResourceInput($request);
        $resourceInputs = array_merge($resourceInputs, $this->extraResourcesFromNote($input['note'], $resourceInputs));
        $group = $input['group'] ?? $this->groupProvisioner->systemGroupFor($visitorId, $this->hasInternalToolInput($resourceInputs));

        $exercise = new Exercise($visitorId);
        $exercise->applyNote($input['note']);
        $em->persist($exercise);
        $em->persist(new ExerciseVersion(
            $exercise,
            $input['title'],
            $group,
            $input['targetMinutes'],
            $input['rhythm'],
            $input['repeatCount'],
            new \DateTimeImmutable('today'),
        ));

        foreach ($resourceInputs as $sortIndex => $resource) {
            $em->persist($this->buildResource($exercise, $sortIndex, $resource));
        }

        $em->flush();

        // A suggestion chip already submits title+resource+minutes in one
        // go and is usable as-is; a bare custom title still needs a
        // resource/time/group - either way, land on the setup step next
        // rather than back on the (now much shorter) list, so "add" always
        // continues into "configure" instead of being a single wide form.
        return $this->redirectToRoute('diary_setup', ['id' => $exercise->getId()]);
    }

    #[Route('/{id}/einrichten', name: 'diary_setup', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function setup(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        ExerciseGroupRepository $groups,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        $currentVersion = $exercise !== null ? $versions->currentFor($exercise, new \DateTimeImmutable('today')) : null;
        if ($exercise === null || $currentVersion === null) {
            return $this->redirectToRoute('diary_index');
        }

        return $this->render('diary/setup.html.twig', [
            'exercise' => $exercise,
            'version' => $currentVersion,
            'resources' => $resources->forExercise($exercise),
            'groups' => $groups->forVisitor($visitorId),
            'tools' => $this->tools->all(),
            'rhythms' => ExerciseVersion::RHYTHMS,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/{id}/bearbeiten', name: 'diary_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        ExerciseResourceRepository $resources,
        ExerciseGroupRepository $groups,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        $currentVersion = $exercise !== null ? $versions->currentFor($exercise, new \DateTimeImmutable('today')) : null;
        if ($exercise === null || $currentVersion === null) {
            return $this->redirectToRoute('diary_index');
        }

        $input = $this->readExerciseInput($request, $groups, $visitorId);
        if ($input['title'] === '') {
            return $this->redirectToRoute('diary_index');
        }

        $resourceInputs = $this->readResourceInput($request);
        $resourceInputs = array_merge($resourceInputs, $this->extraResourcesFromNote($input['note'], $resourceInputs));
        $group = $input['group'] ?? $this->groupProvisioner->systemGroupFor($visitorId, $this->hasInternalToolInput($resourceInputs));

        $exercise->applyNote($input['note']);
        $versions->edit(
            $currentVersion,
            $input['title'],
            $group,
            $input['targetMinutes'],
            $input['rhythm'],
            $input['repeatCount'],
            new \DateTimeImmutable('today'),
        );

        foreach ($resources->forExercise($exercise) as $existing) {
            $em->remove($existing);
        }
        foreach ($resourceInputs as $sortIndex => $resource) {
            $em->persist($this->buildResource($exercise, $sortIndex, $resource));
        }

        $em->flush();

        $this->addFlash('success', 'diary.setup.flash.saved');

        return $this->redirectToRoute('diary_index');
    }

    /**
     * Quick-edit for just rhythm/repeat-count/group straight from the manage
     * list (dropdowns, auto-submit on change) - everything else on the
     * version (title, target minutes) stays exactly as it was, unlike the
     * full diary_edit form which replaces the whole version.
     */
    #[Route('/{id}/schnelleinstellung', name: 'diary_quick_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function quickEdit(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        ExerciseGroupRepository $groups,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        $currentVersion = $exercise !== null ? $versions->currentFor($exercise, new \DateTimeImmutable('today')) : null;
        if ($exercise === null || $currentVersion === null) {
            return $this->redirectToRoute('diary_manage');
        }

        $groupId = (int) $request->request->get('group_id', 0);
        $group = ($groupId > 0 ? $groups->findForVisitor($groupId, $visitorId) : null) ?? $currentVersion->getGroup();

        $rhythm = (string) $request->request->get('rhythm', $currentVersion->getRepeatRhythm());
        if (!in_array($rhythm, ExerciseVersion::RHYTHMS, true)) {
            $rhythm = $currentVersion->getRepeatRhythm();
        }

        $repeatCount = null;
        if ($rhythm === ExerciseVersion::RHYTHM_WEEKLY) {
            $rawCount = (int) $request->request->get('repeat_count', $currentVersion->getRepeatCount() ?? 1);
            $repeatCount = max(1, min(self::MAX_REPEAT_COUNT, $rawCount > 0 ? $rawCount : 1));
        }

        $versions->edit(
            $currentVersion,
            $currentVersion->getTitle(),
            $group,
            $currentVersion->getTargetMinutes(),
            $rhythm,
            $repeatCount,
            new \DateTimeImmutable('today'),
        );

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/archivieren', name: 'diary_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id, Request $request, ExerciseRepository $exercises, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise !== null) {
            $exercise->archive(new \DateTimeImmutable('today'));
            $em->flush();
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/reaktivieren', name: 'diary_reactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reactivate(int $id, Request $request, ExerciseRepository $exercises, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise !== null) {
            $exercise->reactivate();
            $em->flush();
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/loeschen', name: 'diary_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, ExerciseRepository $exercises, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise !== null) {
            // exercise_version/exercise_resource/practice_session.exercise_id
            // are all ON DELETE CASCADE - this removes the whole history too.
            $em->remove($exercise);
            $em->flush();
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/abhaken', name: 'diary_mark_done', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markDone(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        SessionRecorder $sessionRecorder,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        if ($exercise !== null) {
            $version = $versions->currentFor($exercise, new \DateTimeImmutable('today'));
            $note = mb_substr(trim((string) $request->request->get('note')), 0, self::NOTE_MAX_LENGTH);
            $sessionRecorder->completeManually($visitorId, $exercise, $version?->getTargetMinutes(), $note !== '' ? $note : null);
        }

        return $this->redirectToRoute('diary_index');
    }

    /**
     * @param list<array{version: ExerciseVersion, checked: bool, practicedMinutes: ?int, runningSessionId: ?int, weeklyDone: ?int, weeklyTarget: ?int}> $rows
     * @param ExerciseGroup[] $visitorGroups ordered by sortIndex
     * @return list<array{group: ExerciseGroup, rows: array}>
     */
    private function groupRows(array $rows, array $visitorGroups): array
    {
        $byGroupId = [];
        foreach ($rows as $row) {
            $groupId = $row['version']->getGroup()?->getId();
            if ($groupId !== null) {
                $byGroupId[$groupId][] = $row;
            }
        }

        $grouped = [];
        foreach ($visitorGroups as $group) {
            if (isset($byGroupId[$group->getId()])) {
                // Still-open exercises first, already-checked ones sink to
                // the bottom of their group - a done item mixed in with the
                // rest just adds visual noise to "what's left today".
                $groupRows = $byGroupId[$group->getId()];
                usort($groupRows, static fn (array $a, array $b): int => $a['checked'] <=> $b['checked']);
                $grouped[] = ['group' => $group, 'rows' => $groupRows];
            }
        }

        return $grouped;
    }

    /**
     * A link pasted into the note is enough on its own - it doesn't also
     * need to be manually re-entered as a resource. Skips any URL already
     * present among the explicitly submitted resources.
     *
     * @param list<array{type: string, toolId: ?string, url: ?string}> $existing
     * @return list<array{type: string, toolId: ?string, url: ?string}>
     */
    private function extraResourcesFromNote(?string $note, array $existing): array
    {
        $existingUrls = array_column($existing, 'url');

        $extra = [];
        foreach (NoteLinkExtractor::extract($note) as $url) {
            if (mb_strlen($url) <= self::MAX_EXTERNAL_URL_LENGTH && !in_array($url, $existingUrls, true)) {
                $extra[] = ['type' => ExerciseResource::TYPE_EXTERNAL_LINK, 'toolId' => null, 'url' => $url];
            }
        }

        return $extra;
    }

    /**
     * @param list<array{type: string, toolId: ?string, url: ?string}> $resourceInputs
     */
    private function hasInternalToolInput(array $resourceInputs): bool
    {
        foreach ($resourceInputs as $resource) {
            if ($resource['type'] === ExerciseResource::TYPE_INTERNAL_TOOL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ExerciseResource[] $resources
     */
    private function hasInternalToolResource(array $resources): bool
    {
        foreach ($resources as $resource) {
            if ($resource->isInternalTool()) {
                return true;
            }
        }

        return false;
    }

    private function requireVisitorId(Request $request): string
    {
        $v = $request->attributes->get('visitorId');

        return is_string($v) ? $v : '';
    }

    /**
     * Reads the ?datum=YYYY-MM-DD paging param, clamped to never exceed
     * today - future days have no checklist yet.
     */
    private function readViewDate(Request $request, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $raw = (string) $request->query->get('datum');
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            if ($parsed !== false) {
                $parsed = $parsed->setTime(0, 0);
                if ($parsed <= $today) {
                    return $parsed;
                }
            }
        }

        return $today;
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @return array{title: string, note: ?string, group: ?ExerciseGroup, targetMinutes: ?int, rhythm: string, repeatCount: ?int}
     */
    private function readExerciseInput(Request $request, ExerciseGroupRepository $groups, string $visitorId): array
    {
        $title = mb_substr(trim((string) $request->request->get('title')), 0, self::TITLE_MAX_LENGTH);
        $note = mb_substr(trim((string) $request->request->get('note')), 0, self::NOTE_MAX_LENGTH);

        $groupId = (int) $request->request->get('group_id', 0);
        $group = $groupId > 0 ? $groups->findForVisitor($groupId, $visitorId) : null;

        $rhythm = (string) $request->request->get('rhythm');
        if (!in_array($rhythm, ExerciseVersion::RHYTHMS, true)) {
            $rhythm = ExerciseVersion::RHYTHM_NONE;
        }

        $targetMinutes = null;
        $rawMinutes = (int) $request->request->get('target_minutes', 0);
        if ($rawMinutes > 0) {
            $targetMinutes = max(1, min(self::MAX_MINUTES, $rawMinutes));
        }

        $repeatCount = null;
        if ($rhythm === ExerciseVersion::RHYTHM_WEEKLY) {
            $rawCount = (int) $request->request->get('repeat_count', 0);
            $repeatCount = max(1, min(self::MAX_REPEAT_COUNT, $rawCount > 0 ? $rawCount : 1));
        }

        return [
            'title' => $title,
            'note' => $note !== '' ? $note : null,
            'group' => $group,
            'targetMinutes' => $targetMinutes,
            'rhythm' => $rhythm,
            'repeatCount' => $repeatCount,
        ];
    }

    /**
     * Reads parallel resource_type[]/resource_tool_id[]/resource_url[]
     * arrays (one entry per resource row in the form) rather than a nested
     * resources[N][...] shape - simpler to grow/shrink client-side.
     *
     * @return list<array{type: string, toolId: ?string, url: ?string}>
     */
    private function readResourceInput(Request $request): array
    {
        $rows = [];
        $types = $request->request->all('resource_type');
        $toolIds = $request->request->all('resource_tool_id');
        $urls = $request->request->all('resource_url');

        foreach ($types as $index => $type) {
            if ($type === ExerciseResource::TYPE_INTERNAL_TOOL) {
                $toolId = (string) ($toolIds[$index] ?? '');
                if ($toolId !== '' && $this->isKnownTool($toolId)) {
                    $rows[] = ['type' => $type, 'toolId' => $toolId, 'url' => null];
                }
            } elseif ($type === ExerciseResource::TYPE_EXTERNAL_LINK) {
                $url = trim((string) ($urls[$index] ?? ''));
                if (str_starts_with($url, 'https://') && mb_strlen($url) <= self::MAX_EXTERNAL_URL_LENGTH) {
                    $rows[] = ['type' => $type, 'toolId' => null, 'url' => $url];
                }
            }
        }

        return $rows;
    }

    /**
     * @param array{type: string, toolId: ?string, url: ?string} $resource
     */
    private function buildResource(Exercise $exercise, int $sortIndex, array $resource): ExerciseResource
    {
        return $resource['type'] === ExerciseResource::TYPE_INTERNAL_TOOL
            ? ExerciseResource::internalTool($exercise, $sortIndex, $resource['toolId'])
            : ExerciseResource::externalLink($exercise, $sortIndex, $resource['url']);
    }

    private function isKnownTool(string $id): bool
    {
        try {
            $this->tools->get($id);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
