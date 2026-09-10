<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\PracticeSession;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that creates/advances/closes a PracticeSession, whether
 * it's a bare tool round, a plan exercise, or (later) an external
 * resource. Called synchronously at round-start (not lazily from a
 * background sync beacon) so "laufend" is real from the first request.
 */
final class SessionRecorder
{
    public function __construct(
        private readonly PracticeSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function start(
        string $visitorId,
        ?string $tool,
        ?Exercise $exercise = null,
        ?ExerciseResource $resource = null,
        ?array $configSnapshot = null,
    ): PracticeSession {
        $this->discardDanglingSession($visitorId, $tool);

        $session = new PracticeSession($visitorId, $this->generateClientSessionId(), $tool, $exercise, $resource, $configSnapshot);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    /**
     * Idempotent - only the first call per session actually writes.
     */
    public function markInteraction(PracticeSession $session): void
    {
        if ($session->hasInteraction()) {
            return;
        }
        $session->markInteraction();
        $this->em->flush();
    }

    public function finish(PracticeSession $session, ?string $learningState = null): void
    {
        $session->finish($learningState);
        $this->em->flush();
    }

    public function discard(PracticeSession $session): void
    {
        $session->discard();
        $this->em->flush();
    }

    /**
     * A previous round for this exact tool that was started but never
     * touched (no answer/next ever posted) has no browser tab left to
     * resume by the time a *new* round for the same tool starts - resolve
     * it as discarded rather than leaving it "laufend" forever.
     */
    private function discardDanglingSession(string $visitorId, ?string $tool): void
    {
        if ($tool === null) {
            return;
        }

        $dangling = $this->sessions->findRunningWithoutInteraction($visitorId, $tool);
        if ($dangling === []) {
            return;
        }

        foreach ($dangling as $session) {
            $session->discard();
        }
        $this->em->flush();
    }

    private function generateClientSessionId(): string
    {
        return bin2hex(random_bytes(18));
    }
}
