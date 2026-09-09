<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prototype sync endpoint: the practice-sync Stimulus controller calls this
 * on round start, every N seconds while practicing, and on completion, so a
 * round's result survives even if the tab closes before "finished" fires.
 * No auth yet - scoped to the anonymous visitorId cookie (VisitorCookieSubscriber).
 */
#[Route('/api')]
final class PracticeSyncController extends AbstractController
{
    #[Route('/practice-sync', name: 'practice_sync', methods: ['POST'])]
    public function sync(Request $request, PracticeSessionRepository $sessions, EntityManagerInterface $em): JsonResponse
    {
        $visitorId = $request->attributes->get('visitorId');
        if (!is_string($visitorId) || $visitorId === '') {
            return $this->json(['error' => 'missing visitor'], 400);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'invalid body'], 400);
        }

        $clientSessionId = (string) ($data['sessionId'] ?? '');
        $tool = (string) ($data['tool'] ?? '');
        if ($clientSessionId === '' || $tool === '') {
            return $this->json(['error' => 'missing fields'], 400);
        }

        $session = $sessions->findOrCreate($visitorId, $clientSessionId, $tool);
        $session->applySync(
            questionIndex: (int) ($data['questionIndex'] ?? 0),
            totalQuestions: isset($data['totalQuestions']) ? (int) $data['totalQuestions'] : null,
            correctCount: isset($data['correctCount']) ? (int) $data['correctCount'] : null,
            finished: ($data['event'] ?? null) === 'complete',
        );

        $em->flush();

        return $this->json(['ok' => true]);
    }
}
