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
     * A manual "mark done" click rather than real, timed practice - credits
     * exactly creditMinutes (backdating startedAt) so a minute-target
     * exercise is satisfied immediately, the same as genuinely practicing
     * that long would. creditMinutes null (no target) just needs to exist
     * at all, so an instant 0-minute session is fine as-is.
     */
    public function completeManually(string $visitorId, Exercise $exercise, ?int $creditMinutes, ?string $note = null): PracticeSession
    {
        $session = new PracticeSession($visitorId, $this->generateClientSessionId(), null, $exercise);
        if ($creditMinutes !== null && $creditMinutes > 0) {
            $session->backdateStart($creditMinutes);
        }
        $this->em->persist($session);
        $session->finish(note: $note);
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

    public function finish(PracticeSession $session, ?string $learningState = null, ?int $points = null, ?int $timeBonusPoints = null, ?string $note = null): void
    {
        $session->finish($learningState, $points, $timeBonusPoints, $note);
        $this->em->flush();
    }

    public function discard(PracticeSession $session): void
    {
        $session->discard();
        $this->em->flush();
    }

    /**
     * Resuming (or heartbeating into) a session abandoned long enough to
     * exceed the hard finish() cap should start its clock fresh, not keep
     * the true, possibly days-old startedAt - see PracticeSession::
     * rebaseIfAbandoned() for why.
     */
    public function rebaseIfAbandoned(PracticeSession $session): void
    {
        if ($session->rebaseIfAbandoned()) {
            $this->em->flush();
        }
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
