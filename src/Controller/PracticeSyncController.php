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
 * The practice-sync Stimulus controller calls this on round start, every N
 * seconds while practicing, and on completion, so a round's progress
 * survives even if the tab closes before "finished" fires. The
 * PracticeSession row itself already exists by this point - created
 * synchronously at round start by SessionRecorder::start() - so this only
 * ever updates an existing row by its server-issued id, never creates one.
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

        $sessionId = (int) ($data['sessionId'] ?? 0);
        if ($sessionId <= 0) {
            return $this->json(['error' => 'missing fields'], 400);
        }

        $session = $sessions->find($sessionId);
        if ($session === null || $session->getVisitorId() !== $visitorId) {
            return $this->json(['error' => 'unknown session'], 404);
        }

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
