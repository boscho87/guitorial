<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Repository\PracticeSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HistoryController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const STATS_WINDOW = 200;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly HistoryStatsBuilder $statsBuilder,
    ) {
    }

    #[Route('/verlauf', name: 'history_index', methods: ['GET'])]
    public function index(Request $request, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $request->attributes->get('visitorId');
        $visitorId = is_string($visitorId) ? $visitorId : '';

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
                'title' => $title,
                'toolId' => $session->getTool(),
                'startedAt' => $session->getStartedAt(),
                'finishedAt' => $session->getFinishedAt(),
                'questionIndex' => $session->getQuestionIndex(),
                'totalQuestions' => $session->getTotalQuestions(),
                'correctCount' => $session->getCorrectCount(),
            ];
        }, $pageSessions);

        return $this->render('history/index.html.twig', [
            'sessions' => $rows,
            'stats' => $this->statsBuilder->build($statsWindow),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalSessions' => $totalSessions,
            'statsWindow' => self::STATS_WINDOW,
        ]);
    }
}
