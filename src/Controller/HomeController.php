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

final class HomeController extends AbstractController
{
    private const STATS_WINDOW = 200;

    /**
     * The public landing page - explains what Guitorial is for someone who
     * has never seen it before. Kept name "home_index" since "/" is what
     * that name should mean; the former hub content (what "home_index" used
     * to render) now lives at training_index()/"/training" instead.
     */
    #[Route('/', name: 'home_index')]
    public function index(ToolRegistry $toolRegistry): Response
    {
        return $this->render('home/landing.html.twig', [
            'tools' => $toolRegistry->all(),
        ]);
    }

    /**
     * The practical, "I already know Guitorial" entry point - the tool
     * grid, plus a streak hint for returning visitors. This is what used to
     * render at "/" under the name "home_index" - every existing "back to
     * the tools" link app-wide already means this page, so it kept its
     * original template content, just moved to its own route/name.
     */
    #[Route('/training', name: 'training_index')]
    public function training(Request $request, ToolRegistry $toolRegistry, PracticeSessionRepository $sessions, HistoryStatsBuilder $statsBuilder): Response
    {
        $visitorId = $request->attributes->get('visitorId');
        $visitorId = is_string($visitorId) ? $visitorId : '';

        $streak = $visitorId !== ''
            ? $statsBuilder->build($sessions->forVisitor($visitorId, self::STATS_WINDOW))['currentStreak']
            : 0;

        return $this->render('home/training.html.twig', [
            'tools' => $toolRegistry->all(),
            'streak' => $streak,
        ]);
    }
}
