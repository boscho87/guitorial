<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\History\HistoryGroupStatsBuilder;
use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\ExerciseVersion;
use App\Repository\ExerciseGroupRepository;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HistoryController extends AbstractController
{
    private const CSRF_INTENTION = 'history';
    private const PAGE_SIZE = 25;
    private const STATS_WINDOW = 200;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly HistoryStatsBuilder $statsBuilder,
        private readonly HistoryGroupStatsBuilder $groupStatsBuilder,
    ) {
    }

    #[Route('/verlauf', name: 'history_index', methods: ['GET'])]
    public function index(Request $request, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $this->requireVisitorId($request);

        // Stats/chart cover a fixed recent window regardless of which table
        // page is being viewed - paginating the table must not change them.
        $statsWindow = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];

        $totalSessions = $visitorId !== '' ? $sessions->countForVisitor($visitorId) : 0;
        $totalPages = max(1, (int) ceil($totalSessions / self::PAGE_SIZE));
        $page = max(1, min($totalPages, (int) $request->query->get('seite', 1)));
        $pageSessions = $visitorId !== ''
            ? $sessions->forVisitorPage($visitorId, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)
            : [];

        $rows = array_map(function ($session) {
            try {
                $title = $this->tools->get($session->getTool())['title'];
            } catch (\InvalidArgumentException) {
                $title = $session->getTool();
            }

            return [
                'id' => $session->getId(),
                'title' => $title,
                'toolId' => $session->getTool(),
                'status' => $session->getStatus(),
                'startedAt' => $session->getStartedAt(),
                'finishedAt' => $session->getFinishedAt(),
                'questionIndex' => $session->getQuestionIndex(),
                'totalQuestions' => $session->getTotalQuestions(),
                'correctCount' => $session->getCorrectCount(),
                'promotable' => $session->getTool() !== null && $session->getExercise() === null && $session->isFinished(),
            ];
        }, $pageSessions);

        return $this->render('history/index.html.twig', [
            'sessions' => $rows,
            'stats' => $this->statsBuilder->build($statsWindow),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalSessions' => $totalSessions,
            'statsWindow' => self::STATS_WINDOW,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/verlauf/gruppen', name: 'history_groups', methods: ['GET'])]
    public function groups(
        Request $request,
        PracticeSessionRepository $sessions,
        ExerciseVersionRepository $versions,
        ExerciseGroupRepository $groups,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');

        $allSessions = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];
        $currentVersions = $this->currentVersionsFor($visitorId, $versions, $today);
        $visitorGroups = $visitorId !== '' ? $groups->forVisitor($visitorId) : [];

        return $this->render('history/groups.html.twig', [
            'rows' => $this->groupStatsBuilder->build($allSessions, $currentVersions, $visitorGroups, $today),
        ]);
    }

    #[Route('/verlauf/uebung/{id}', name: 'history_exercise_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exerciseDetail(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseVersionRepository $versions,
        PracticeSessionRepository $sessions,
    ): Response {
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        if ($exercise === null) {
            return $this->redirectToRoute('history_groups');
        }

        $currentVersion = $versions->currentFor($exercise, new \DateTimeImmutable('today'));
        $exerciseSessions = array_values(array_filter(
            $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [],
            static fn ($s) => $s->getExercise() === $exercise,
        ));

        return $this->render('history/exercise_detail.html.twig', [
            'exercise' => $exercise,
            'version' => $currentVersion,
            'sessions' => $exerciseSessions,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/verlauf/{session}/als-uebung-uebernehmen', name: 'history_promote_to_exercise', methods: ['POST'], requirements: ['session' => '\d+'])]
    public function promoteToExercise(
        int $session,
        Request $request,
        PracticeSessionRepository $sessions,
        TranslatorInterface $translator,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $practiceSession = $sessions->find($session);

        if (
            $practiceSession === null
            || $practiceSession->getVisitorId() !== $visitorId
            || $practiceSession->getTool() === null
            || $practiceSession->getExercise() !== null
        ) {
            return $this->redirectToRoute('history_index');
        }

        try {
            $title = $translator->trans($this->tools->get($practiceSession->getTool())['title']);
        } catch (\InvalidArgumentException) {
            $title = $practiceSession->getTool();
        }

        $exercise = new Exercise($visitorId);
        $em->persist($exercise);
        $em->persist(new ExerciseVersion($exercise, $title, null, null, ExerciseVersion::RHYTHM_NONE, null, new \DateTimeImmutable('today')));
        $em->persist(ExerciseResource::internalTool($exercise, 0, $practiceSession->getTool(), $practiceSession->getConfigSnapshot()));
        $em->flush();

        $this->addFlash('success', 'history.promote.flash');

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/verlauf/loeschen', name: 'history_delete_all', methods: ['POST'])]
    public function deleteAll(Request $request, PracticeSessionRepository $sessions, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        foreach ($visitorId !== '' ? $sessions->forVisitor($visitorId, PHP_INT_MAX) : [] as $session) {
            $em->remove($session);
        }
        $em->flush();

        return $this->redirectToRoute('history_index');
    }

    #[Route('/verlauf/uebung/{id}/loeschen', name: 'history_exercise_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteExerciseHistory(
        int $id,
        Request $request,
        ExerciseRepository $exercises,
        PracticeSessionRepository $sessions,
        EntityManagerInterface $em,
    ): Response {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $exercise = $exercises->findForVisitor($id, $visitorId);
        if ($exercise === null) {
            return $this->redirectToRoute('history_groups');
        }

        foreach ($sessions->forVisitor($visitorId, PHP_INT_MAX) as $session) {
            if ($session->getExercise() === $exercise) {
                $em->remove($session);
            }
        }
        $em->flush();

        return $this->redirectToRoute('history_groups');
    }

    /**
     * @return ExerciseVersion[] one current (today-active) version per exercise this visitor has
     */
    private function currentVersionsFor(string $visitorId, ExerciseVersionRepository $versions, \DateTimeImmutable $today): array
    {
        if ($visitorId === '') {
            return [];
        }

        $current = [];
        foreach ($versions->forVisitor($visitorId) as $version) {
            if ($version->isActiveOn($today) && $version->getExercise()->isActiveOn($today)) {
                $current[] = $version;
            }
        }

        return $current;
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
