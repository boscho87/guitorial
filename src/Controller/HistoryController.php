<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\ToolRegistry;
use App\Repository\PracticeSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HistoryController extends AbstractController
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    #[Route('/verlauf', name: 'history_index', methods: ['GET'])]
    public function index(Request $request, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $request->attributes->get('visitorId');

        $rows = array_map(function ($session) {
            try {
                $title = $this->tools->get($session->getTool())['title'];
            } catch (\InvalidArgumentException) {
                $title = $session->getTool();
            }

            return [
                'title' => $title,
                'startedAt' => $session->getStartedAt(),
                'finishedAt' => $session->getFinishedAt(),
                'questionIndex' => $session->getQuestionIndex(),
                'totalQuestions' => $session->getTotalQuestions(),
                'correctCount' => $session->getCorrectCount(),
            ];
        }, is_string($visitorId) ? $sessions->forVisitor($visitorId) : []);

        return $this->render('history/index.html.twig', [
            'sessions' => $rows,
        ]);
    }
}
