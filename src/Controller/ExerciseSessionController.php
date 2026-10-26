<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\YouTubeUrlParser;
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
 *
 * At most one PracticeSession is ever "laufend" for a given exercise -
 * startManual()/startExternal() resume an already-running one (see
 * PracticeSessionRepository::findRunningForExercise()) instead of creating
 * a second, overlapping session that would just orphan the first (never
 * shown again, since the card only ever links to the most recently created
 * one, and never finished/discarded either).
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
        $visitorId = $this->requireVisitorId($request);
        $exercise = $this->exercises->findForVisitor($id, $visitorId);
        if ($exercise === null) {
            return $this->redirectToRoute('diary_index');
        }

        $session = $this->sessions->findRunningForExercise($visitorId, $exercise)
            ?? $this->sessionRecorder->start($visitorId, null, exercise: $exercise);

        return $this->redirectToRoute('exercise_session_running', $this->runningRouteParams($session, $request));
    }

    #[Route('/uebung/{id}/ressource/{resourceId}/starten', name: 'exercise_start_external', methods: ['POST'], requirements: ['id' => '\d+', 'resourceId' => '\d+'])]
    public function startExternal(int $id, int $resourceId, Request $request): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        $exercise = $this->exercises->findForVisitor($id, $visitorId);
        if ($exercise === null) {
            return $this->redirectToRoute('diary_index');
        }

        $resource = $this->findResource($exercise, $resourceId);
        if ($resource === null || $resource->isInternalTool()) {
            return $this->redirectToRoute('diary_index');
        }

        // Already running (started manually, or via a different resource on
        // this same exercise) - resume that one rather than starting a
        // second, overlapping session that would just orphan the first.
        $session = $this->sessions->findRunningForExercise($visitorId, $exercise)
            ?? $this->sessionRecorder->start($visitorId, null, exercise: $exercise, resource: $resource);

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

        // At most one note per exercise per day (see SessionRecorder::
        // finish()), so the most recent note-bearing session, if it's from
        // today, *is* today's note - prefill the (editable) note field with
        // it instead of only showing it as inert reference text, so it
        // stays visible/buildable-on all day. An older note stays reference-
        // only - starting fresh today shouldn't silently inherit stale text.
        $mostRecentNoteSession = $exercise !== null ? $this->sessions->mostRecentNoteForExercise($exercise) : null;
        $isNoteFromToday = $mostRecentNoteSession !== null
            && $mostRecentNoteSession->getFinishedAt()?->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d');
        $todayNote = $isNoteFromToday ? $mostRecentNoteSession->getNote() : null;
        $previousNote = !$isNoteFromToday ? $mostRecentNoteSession : null;

        // Today's total for this exercise, not just this one session's own
        // elapsed time - finishing earlier today (e.g. a first pass this
        // morning) and starting again later shouldn't reset the clock to
        // 0:00, it should keep adding to the same day's running total.
        $todayAlreadyFinishedSeconds = 0;
        if ($exercise !== null) {
            foreach ($this->sessions->finishedForExerciseOnDate($exercise, new \DateTimeImmutable('today')) as $finishedToday) {
                $todayAlreadyFinishedSeconds += $finishedToday->getFinishedAt()->getTimestamp() - $finishedToday->getStartedAt()->getTimestamp();
            }
        }

        $resource = $session->getResource();
        $openableResources = $resource === null && $exercise !== null
            ? array_values(array_filter($this->resources->forExercise($exercise), static fn (ExerciseResource $r): bool => !$r->isInternalTool()))
            : [];

        return $this->render('exercise_session/running.html.twig', [
            'session' => $session,
            'title' => $title,
            'resource' => $resource,
            // Started generically (2+ resources, or none picked yet) - the
            // choice of what to open lives here on the running page, never
            // as a list of raw-URL "start" buttons on the Heute card.
            'openableResources' => $openableResources,
            // Keyed by resource id - a YouTube resource can be played
            // in-page instead of in a new tab, so watching along doesn't
            // background this tab and pause the timer (see
            // timer_controller.js's pauseWhenHidden).
            'youtubeVideoIds' => $this->youtubeVideoIds(array_filter([$resource, ...$openableResources])),
            // Anchored on this session's own creditable (capped) elapsed
            // time plus whatever's already finished today for this exercise
            // - so a session left open in the background while browsing
            // back to Heute doesn't show the away-time as if it had kept
            // running, and finishing earlier today then starting again
            // continues the day's total instead of resetting to 0:00.
            'startedAtMs' => (time() - $session->creditableSecondsElapsed() - $todayAlreadyFinishedSeconds) * 1000,
            'targetSeconds' => $this->targetSeconds($session),
            'learningStates' => PracticeSession::LEARNING_STATES,
            'csrfIntention' => self::CSRF_INTENTION,
            'previousNote' => $previousNote?->getNote(),
            'previousNoteDate' => $previousNote?->getFinishedAt(),
            'todayNote' => $todayNote,
            'returnRouteId' => $target['id'] ?? null,
            'practiceToolsExerciseId' => $exercise?->getId(),
            'practiceToolsConfig' => $exercise?->getPracticeToolsSnapshot(),
            'practiceToolsSaveUrl' => $exercise !== null ? $this->generateUrl('exercise_practice_tools_save', ['id' => $exercise->getId()]) : null,
            'hideChromeOnMobile' => true,
            // Rendered embedded in this page's own content below instead -
            // see running.html.twig.
            'hidePracticeTools' => true,
            'exercise' => $exercise,
        ]);
    }

    /**
     * Same toggle as DiaryController::togglePriority() (the exercise setup
     * page), just reachable without leaving the running page mid-session -
     * redirects back to this same page by session id rather than to the
     * setup page or Heute.
     */
    #[Route('/sitzung/{id}/priorisieren', name: 'exercise_session_toggle_priority', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function togglePriority(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session === null) {
            return $this->redirectToRoute('diary_index');
        }

        $exercise = $session->getExercise();
        if ($exercise !== null) {
            $exercise->togglePriority();
            $em->flush();
        }

        return $this->redirectToRoute('exercise_session_running', ['id' => $id]);
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

    /**
     * Ends a pause (manual "P" or an unconfirmed check-in) - the client
     * sent one last heartbeat right before stopping, so the gap since
     * updatedAt is the paused time; see PracticeSession::resumeAfterPause().
     */
    #[Route('/sitzung/{id}/fortsetzen', name: 'exercise_session_resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resume(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $session = $this->requireRunningSession($id, $request);
        if ($session !== null) {
            $session->resumeAfterPause();
            $em->flush();
        }

        return new Response('', 204);
    }

    /**
     * Saves this exercise's own metronome/drone setup (see
     * Exercise::practiceToolsSnapshot) - called from the global
     * practice-tools widget while its running page is open, debounced.
     * Keyed by exercise, not by session: the setting should stick around
     * for next time regardless of which particular session loaded it.
     */
    #[Route('/uebung/{id}/metronom', name: 'exercise_practice_tools_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function savePracticeTools(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $exercise = $this->exercises->findForVisitor($id, $this->requireVisitorId($request));
        if ($exercise === null) {
            return new Response('', 404);
        }

        $droneNote = $request->request->get('droneNote');

        $exercise->applyPracticeToolsSnapshot([
            'bpm' => max(30, min(240, (int) $request->request->get('bpm', 100))),
            'beats' => in_array((int) $request->request->get('beats', 4), [2, 3, 4, 6], true) ? (int) $request->request->get('beats') : 4,
            'droneNote' => $droneNote !== null && $droneNote !== '' ? max(0, min(11, (int) $droneNote)) : null,
            'fifth' => $request->request->getBoolean('fifth'),
            'octave' => max(2, min(4, (int) $request->request->get('octave', 3))),
        ]);
        $em->flush();

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
        // One-shot celebration flag for confetti_controller.js - finish()
        // always redirects (diary_index or routine_show, see
        // redirectAfterSession()), never a turbo-frame update, so there's
        // no in-page moment to trigger an animation from directly; the
        // landing template reads+clears this flash once instead.
        if ($learningState === PracticeSession::LEARNING_STATE_CONFIDENT) {
            $this->addFlash('celebrate', '1');
        }

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

    /**
     * @param ExerciseResource[] $resources
     *
     * @return array<int, string>
     */
    private function youtubeVideoIds(array $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            $url = $resource->getExternalUrl();
            $videoId = $url !== null ? YouTubeUrlParser::videoId($url) : null;
            if ($videoId !== null) {
                $ids[$resource->getId()] = $videoId;
            }
        }

        return $ids;
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
