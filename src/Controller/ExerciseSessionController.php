<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Session\ReturnRouteResolver;
use App\Domain\Session\SessionRecorder;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Entity\PracticeSession;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseResourceRepository;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Starts and runs a plan exercise's session outside the 5 graded tools: a
 * manual exercise (no resource, just a timer) or an external resource (an
 * outside URL). Starting either just moves straight to the running/timer
 * page - an external resource is never opened automatically, only via the
 * explicit "Ressource öffnen" link there, so starting never surprises the
 * visitor with an extra tab they didn't ask for. Internal-tool exercises
 * skip this entirely - "Start" links straight to that tool's own settings
 * page with ?uebung=&ressource=, see ExercisePlanLink; the resulting
 * PracticeSession is created there.
 */
#[Route('/tagebuch')]
final class ExerciseSessionController extends AbstractController
{
    private const CSRF_INTENTION = 'exercise_session';
    private const NOTE_MAX_LENGTH = 2000;

    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExerciseRepository $exercises,
        private readonly ExerciseResourceRepository $resources,
        private readonly ExerciseVersionRepository $versions,
        private readonly PracticeSessionRepository $sessions,
        private readonly ReturnRouteResolver $returnRoute,
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

        return $this->redirectToRoute('exercise_session_running', $this->runningRouteParams($session, $request));
    }

    #[Route('/uebung/{id}/ressource/{resourceId}/starten', name: 'exercise_start_external', methods: ['POST'], requirements: ['id' => '\d+', 'resourceId' => '\d+'])]
    public function startExternal(int $id, int $resourceId, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $this->exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise === null) {
            return $this->redirectToRoute('diary_index');
        }

        $resource = $this->findResource($exercise, $resourceId);
        if ($resource === null || $resource->isInternalTool()) {
            return $this->redirectToRoute('diary_index');
        }

        $session = $this->sessionRecorder->start($this->requireVisitorId($request), null, exercise: $exercise, resource: $resource);

        return $this->redirectToRoute('exercise_session_running', $this->runningRouteParams($session, $request));
    }

    #[Route('/sitzung/{id}', name: 'exercise_session_running', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function running(int $id, Request $request): Response
    {
        $session = $this->requireRunningSession($id, $request);
        if ($session === null) {
            return $this->redirectToRoute('diary_index');
        }

        $this->sessionRecorder->rebaseIfAbandoned($session);

        $exercise = $session->getExercise();
        $title = $exercise !== null ? $this->currentVersionTitle($exercise) : null;
        $target = $this->returnRoute->resolveFromQuery($request);

        return $this->render('exercise_session/running.html.twig', [
            'session' => $session,
            'title' => $title,
            'resource' => $session->getResource(),
            // Started generically (2+ resources, or none picked yet) - the
            // choice of what to open lives here on the running page, never
            // as a list of raw-URL "start" buttons on the Heute card.
            'openableResources' => $session->getResource() === null && $exercise !== null
                ? array_values(array_filter($this->resources->forExercise($exercise), static fn (ExerciseResource $r): bool => !$r->isInternalTool()))
                : [],
            'startedAtMs' => $session->getStartedAt()->getTimestamp() * 1000,
            'targetSeconds' => $this->targetSeconds($session),
            'learningStates' => PracticeSession::LEARNING_STATES,
            'csrfIntention' => self::CSRF_INTENTION,
            'returnRouteId' => $target['id'] ?? null,
            'hideChromeOnMobile' => true,
        ]);
    }

    /**
     * "Still there" heartbeat while the running page stays open - posted
     * every ~20s by a small Stimulus controller. Manual/external exercise
     * sessions otherwise have no sync at all, unlike the 5 graded tools'
     * rounds (practice-sync). Bumps only updatedAt (PracticeSession::touch()),
     * which finish() later uses to cap the credited duration if the tab
     * stops sending heartbeats (closed, backgrounded, laptop asleep, ...)
     * long before an eventual, much later finish/discard click.
     */
    #[Route('/sitzung/{id}/lebenszeichen', name: 'exercise_session_heartbeat', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function heartbeat(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session !== null) {
            $session->rebaseIfAbandoned();
            $session->touch();
            $em->flush();
        }

        return new Response('', 204);
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
        $note = mb_substr(trim((string) $request->request->get('note')), 0, self::NOTE_MAX_LENGTH);
        $this->sessionRecorder->finish(
            $session,
            in_array($learningState, PracticeSession::LEARNING_STATES, true) ? $learningState : null,
            note: $note !== '' ? $note : null,
        );

        $this->addFlash('success', 'exercise_session.flash.finished');

        return $this->redirectAfterSession($request);
    }

    #[Route('/sitzung/{id}/verwerfen', name: 'exercise_session_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session !== null) {
            $this->sessionRecorder->discard($session);
        }

        return $this->redirectAfterSession($request);
    }

    /**
     * @return array<string, int|string>
     */
    private function runningRouteParams(PracticeSession $session, Request $request): array
    {
        $params = ['id' => $session->getId()];
        $target = $this->returnRoute->resolveFromRequestBody($request);
        if ($target !== null) {
            $params['return_route'] = $target['route'];
            $params['return_route_id'] = $target['id'];
        }

        return $params;
    }

    private function redirectAfterSession(Request $request): Response
    {
        $target = $this->returnRoute->resolveFromRequestBody($request);

        return $target !== null
            ? $this->redirectToRoute($target['route'], ['id' => $target['id']])
            : $this->redirectToRoute('diary_index');
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
