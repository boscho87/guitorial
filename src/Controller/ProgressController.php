<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Domain\Session\PersonalRecords;
use App\Repository\PracticeSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Fortschritt" tab of Meine Übungen - purely aggregated, read-only
 * numbers (HistoryStatsBuilder + PersonalRecords), no data of its own.
 * Deliberately no leaderboard/social comparison - every number here is
 * scoped to the current visitor only.
 */
final class ProgressController extends AbstractController
{
    private const STATS_WINDOW = 200;
    private const GRADED_TOOLS = ['tonart-erkennen', 'kapo-trainer', 'funktion-bestimmen', 'griffbrett'];

    public function __construct(
        private readonly HistoryStatsBuilder $statsBuilder,
        private readonly PersonalRecords $personalRecords,
        private readonly ToolRegistry $tools,
    ) {
    }

    #[Route('/fortschritt', name: 'progress_index', methods: ['GET'])]
    public function index(Request $request, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $allSessions = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];
        $stats = $this->statsBuilder->build($allSessions);

        $records = [];
        foreach (self::GRADED_TOOLS as $toolId) {
            $best = $visitorId !== '' ? $this->personalRecords->bestForTool($visitorId, $toolId) : null;
            if ($best !== null) {
                $records[] = ['title' => $this->tools->get($toolId)['title'], 'points' => $best];
            }
        }

        return $this->render('progress/index.html.twig', [
            'stats' => $stats,
            'records' => $records,
        ]);
    }

    private function requireVisitorId(Request $request): string
    {
        $v = $request->attributes->get('visitorId');

        return is_string($v) ? $v : '';
    }
}
