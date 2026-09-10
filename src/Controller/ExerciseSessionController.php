<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Session\SessionRecorder;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\PracticeSession;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Starts and runs a plan exercise's session outside the 5 graded tools: a
 * manual exercise (no resource, just a timer) or an external resource (an
 * outside URL, opened in a new tab while this tab keeps a real, wall-clock
 * timer running). Internal-tool exercises skip this entirely - "Start"
 * links straight to that tool's own settings page with ?uebung=&ressource=,
 * see ExercisePlanLink; the resulting PracticeSession is created there.
 */
#[Route('/tagebuch')]
final class ExerciseSessionController extends AbstractController
{
    private const CSRF_INTENTION = 'exercise_session';

    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExerciseRepository $exercises,
        private readonly ExerciseResourceRepository $resources,
        private readonly ExerciseVersionRepository $versions,
        private readonly PracticeSessionRepository $sessions,
    ) {
    }

    #[Route('/uebung/{id}/starten', name: 'exercise_start_manual', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startManual(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $this->exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise === null) {
            return $this->redirectToRoute('diary_index');
        }

        $session = $this->sessionRecorder->start($this->requireVisitorId($request), null, exercise: $exercise);

        return $this->redirectToRoute('exercise_session_running', ['id' => $session->getId()]);
    }

    #[Route('/uebung/{id}/ressource/{resourceId}/starten', name: 'exercise_start_external', methods: ['POST'], requirements: ['id' => '\d+', 'resourceId' => '\d+'])]
    public function startExternal(int $id, int $resourceId, Request $request): JsonResponse
    {
        $this->assertValidCsrf($request);
        $exercise = $this->exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise === null) {
            return $this->json(['error' => 'not found'], 404);
        }

        $resource = $this->findResource($exercise, $resourceId);
        if ($resource === null || $resource->isInternalTool()) {
            return $this->json(['error' => 'not found'], 404);
        }

        $session = $this->sessionRecorder->start($this->requireVisitorId($request), null, exercise: $exercise, resource: $resource);

        return $this->json([
            'runningUrl' => $this->generateUrl('exercise_session_running', ['id' => $session->getId()]),
        ]);
    }

    #[Route('/sitzung/{id}', name: 'exercise_session_running', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function running(int $id, Request $request): Response
    {
        $session = $this->requireRunningSession($id, $request);
        if ($session === null) {
            return $this->redirectToRoute('diary_index');
        }

        $exercise = $session->getExercise();
        $title = $exercise !== null ? $this->currentVersionTitle($exercise) : null;

        return $this->render('exercise_session/running.html.twig', [
            'session' => $session,
            'title' => $title,
            'resource' => $session->getResource(),
            'startedAtMs' => $session->getStartedAt()->getTimestamp() * 1000,
            'targetSeconds' => $this->targetSeconds($session),
            'learningStates' => PracticeSession::LEARNING_STATES,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/sitzung/{id}/abschliessen', name: 'exercise_session_finish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function finish(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session === null) {
            return $this->redirectToRoute('diary_index');
        }

        $learningState = (string) $request->request->get('learning_state');
        $this->sessionRecorder->finish($session, in_array($learningState, PracticeSession::LEARNING_STATES, true) ? $learningState : null);

        $this->addFlash('success', 'exercise_session.flash.finished');

        return $this->redirectToRoute('diary_index');
    }

    #[Route('/sitzung/{id}/verwerfen', name: 'exercise_session_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session !== null) {
            $this->sessionRecorder->discard($session);
        }

        return $this->redirectToRoute('diary_index');
    }

    private function requireRunningSession(int $id, Request $request): ?PracticeSession
    {
        $session = $this->sessions->find($id);
        if ($session === null || $session->getVisitorId() !== $this->requireVisitorId($request) || !$session->isRunning()) {
            return null;
        }

        return $session;
    }

    private function findResource(Exercise $exercise, int $resourceId): ?ExerciseResource
    {
        foreach ($this->resources->forExercise($exercise) as $resource) {
            if ($resource->getId() === $resourceId) {
                return $resource;
            }
        }

        return null;
    }

    private function currentVersionTitle(Exercise $exercise): ?string
    {
        return $this->versions->currentFor($exercise, new \DateTimeImmutable('today'))?->getTitle();
    }

    private function targetSeconds(PracticeSession $session): ?int
    {
        $exercise = $session->getExercise();
        if ($exercise === null) {
            return null;
        }

        $targetMinutes = $this->versions->currentFor($exercise, new \DateTimeImmutable('today'))?->getTargetMinutes();

        return $targetMinutes !== null ? $targetMinutes * 60 : null;
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
