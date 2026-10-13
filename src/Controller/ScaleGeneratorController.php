<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Session\ExercisePlanLink;
use App\Domain\Session\ReturnRouteResolver;
use App\Domain\Session\SessionRecorder;
use App\Domain\Session\ToolConfigSnapshot;
use App\Domain\Theory\Note;
use App\Domain\Theory\Scale;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Repository\PracticeSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A random tone generator for practice - no right/wrong answer, just "here's
 * the next tone". Two modes: "tones" draws from a user-picked subset of the
 * 12 chromatic tones (the original v1 behaviour); "scale" draws the 7 notes
 * of one chosen Scale (tonic + Dur/Moll/Harmonisch/Melodisch) instead, for
 * practicing a specific scale pattern.
 *
 * A round is bounded (one draw per active tone, no repeats) rather than
 * open-ended, matching the other tools' settings -> practice -> summary
 * flow. Optional auto-advance moves on after N seconds without a click.
 */
#[Route('/skalen-generator')]
final class ScaleGeneratorController extends AbstractController
{
    private const TOOL = 'skalen-generator';
    private const SESSION_ROUND = 'scale_generator.round';
    private const CSRF_INTENTION = 'scale-generator';
    private const MAX_AUTO_ADVANCE_SECONDS = 120;

    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExercisePlanLink $planLink,
        private readonly PracticeSessionRepository $sessions,
        private readonly ReturnRouteResolver $returnRoute,
    ) {
    }

    /** @return string[] */
    private static function allTones(): array
    {
        return array_map(static fn (int $i): string => Note::nameFromIndex($i), range(0, 11));
    }

    /** @return array<string, string> typeId => German label */
    private static function scaleTypeOptions(): array
    {
        return array_combine(Scale::typeIds(), array_map(Scale::typeLabel(...), Scale::typeIds()));
    }

    #[Route('', name: 'scale_generator_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);
        $plan = $this->resolvePlanLink($request, $request->query->get('uebung'), $request->query->get('ressource'));
        $lastConfig = $round === null && $plan !== null ? $this->lastConfigFor($plan['exercise']) : null;
        $target = $this->returnRoute->resolve($request->query->get('return_route'), $request->query->get('return_route_id'));

        $effective = $round ?? $lastConfig;
        $mode = $effective['mode'] ?? 'tones';
        $scaleId = $mode === 'scale' ? ($effective['scaleId'] ?? null) : null;
        [$scaleTonic, $scaleType] = $scaleId !== null ? explode(':', $scaleId, 2) : [self::allTones()[0], Scale::typeIds()[0]];

        return $this->render('scale_generator/settings.html.twig', [
            'allTones' => self::allTones(),
            'activeTones' => $mode === 'tones' ? ($effective['tones'] ?? self::allTones()) : self::allTones(),
            'autoAdvanceSeconds' => $effective['autoAdvanceSeconds'] ?? 0,
            'mode' => $mode,
            'scaleTonic' => $scaleTonic,
            'scaleType' => $scaleType,
            'scaleTypes' => self::scaleTypeOptions(),
            'planExerciseId' => $plan !== null ? $plan['exercise']->getId() : null,
            'planResourceId' => $plan !== null ? $plan['resource']->getId() : null,
            'returnRouteId' => $target['id'] ?? null,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'scale_generator_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $scaleId = null;
        if ($request->request->get('mode') === 'scale') {
            $tonic = (string) $request->request->get('scale_tonic');
            $typeId = (string) $request->request->get('scale_type');
            $scaleId = in_array($tonic, self::allTones(), true) && in_array($typeId, Scale::typeIds(), true)
                ? $tonic . ':' . $typeId
                : null;
        }

        if ($scaleId !== null) {
            $mode = 'scale';
            $tones = Scale::get($scaleId)->notes;
        } else {
            $mode = 'tones';
            $tones = array_values(array_intersect($request->request->all('tones'), self::allTones()));
            if ($tones === []) {
                $tones = self::allTones();
            }
        }

        $autoAdvanceSeconds = max(0, min(
            self::MAX_AUTO_ADVANCE_SECONDS,
            (int) $request->request->get('auto_advance_seconds', 0),
        ));

        $plan = $this->resolvePlanLink($request, $request->request->get('uebung'), $request->request->get('ressource'));
        $target = $this->returnRoute->resolveFromRequestBody($request);
        $this->beginRound($request, $tones, $autoAdvanceSeconds, $mode, $scaleId, $plan['exercise'] ?? null, $plan['resource'] ?? null, $target);

        return $this->redirectToRoute('scale_generator_practice');
    }

    #[Route('/uebung', name: 'scale_generator_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        if ($this->currentRound($request) === null) {
            return $this->redirectToRoute('scale_generator_settings');
        }

        return $this->render('scale_generator/practice.html.twig', $this->viewData($request));
    }

    #[Route('/naechste', name: 'scale_generator_next', methods: ['POST'])]
    public function next(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('scale_generator_settings');
        }

        $practiceSession = $this->sessions->find($round['practiceSessionId']);
        if ($practiceSession !== null) {
            $this->sessionRecorder->markInteraction($practiceSession);
        }

        if ($round['queue'] === []) {
            $round['finished'] = true;
            $round['current'] = null;
            $session->set(self::SESSION_ROUND, $round);
            if ($practiceSession !== null) {
                $this->sessionRecorder->finish($practiceSession);
            }
        } else {
            $round['current'] = array_shift($round['queue']);
            $round['questionIndex']++;
            $session->set(self::SESSION_ROUND, $round);
        }

        return $this->render('scale_generator/practice.html.twig', $this->viewData($request));
    }

    #[Route('/neue-runde', name: 'scale_generator_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $tones = $round !== null ? $round['tones'] : self::allTones();
        $autoAdvanceSeconds = $round !== null ? $round['autoAdvanceSeconds'] : 0;
        $mode = $round['mode'] ?? 'tones';
        $scaleId = $round['scaleId'] ?? null;

        $this->beginRound($request, $tones, $autoAdvanceSeconds, $mode, $scaleId);

        return $this->redirectToRoute('scale_generator_practice');
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @return array{exercise: Exercise, resource: ExerciseResource}|null
     */
    private function resolvePlanLink(Request $request, mixed $exerciseId, mixed $resourceId): ?array
    {
        $visitorId = $request->attributes->get('visitorId');

        return $this->planLink->resolve(
            is_string($visitorId) ? $visitorId : '',
            $exerciseId !== null ? (string) $exerciseId : null,
            $resourceId !== null ? (string) $resourceId : null,
            self::TOOL,
        );
    }

    /**
     * @return array{tones: string[], queue: string[], current: string|null, questionIndex: int, totalQuestions: int, finished: bool, autoAdvanceSeconds: int, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['tones'], $round['queue'], $round['questionIndex'], $round['totalQuestions'], $round['finished'], $round['autoAdvanceSeconds'], $round['startedAt'], $round['practiceSessionId'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $tones
     * @param array{route: string, id: int}|null $returnTarget
     */
    private function beginRound(Request $request, array $tones, int $autoAdvanceSeconds, string $mode, ?string $scaleId, ?Exercise $exercise = null, ?ExerciseResource $resource = null, ?array $returnTarget = null): void
    {
        $queue = $tones;
        shuffle($queue);
        // Shift before building the array literal - 'queue' => $queue would
        // otherwise capture the pre-shift snapshot (PHP evaluates array
        // literal values left-to-right), duplicating the first tone.
        $current = array_shift($queue);

        $visitorId = $request->attributes->get('visitorId');
        $practiceSession = $this->sessionRecorder->start(
            is_string($visitorId) ? $visitorId : '',
            self::TOOL,
            exercise: $exercise,
            resource: $resource,
            configSnapshot: ToolConfigSnapshot::scaleGenerator($tones, $autoAdvanceSeconds, $mode, $scaleId),
        );

        $request->getSession()->set(self::SESSION_ROUND, [
            'tones' => $tones,
            'mode' => $mode,
            'scaleId' => $scaleId,
            'queue' => $queue,
            'current' => $current,
            'questionIndex' => 1,
            'totalQuestions' => count($tones),
            'finished' => false,
            'autoAdvanceSeconds' => $autoAdvanceSeconds,
            'startedAt' => microtime(true),
            'practiceSessionId' => $practiceSession->getId(),
            'fromPlan' => $exercise !== null,
            'returnRouteId' => $returnTarget['id'] ?? null,
        ]);
    }

    /**
     * @return array{tones: string[], autoAdvanceSeconds: int, mode: string, scaleId: string|null}|null
     */
    private function lastConfigFor(Exercise $exercise): ?array
    {
        $snapshot = $this->sessions->mostRecentForExercise($exercise, self::TOOL)?->getConfigSnapshot();

        return is_array($snapshot) && isset($snapshot['tones'], $snapshot['autoAdvanceSeconds'])
            ? [
                'tones' => $snapshot['tones'],
                'autoAdvanceSeconds' => $snapshot['autoAdvanceSeconds'],
                'mode' => is_string($snapshot['mode'] ?? null) ? $snapshot['mode'] : 'tones',
                'scaleId' => is_string($snapshot['scaleId'] ?? null) ? $snapshot['scaleId'] : null,
            ]
            : null;
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request): array
    {
        $round = $this->currentRound($request);
        $mode = $round['mode'] ?? 'tones';
        $scaleId = $round['scaleId'] ?? null;

        return [
            'sessionId' => $round['practiceSessionId'],
            'allTones' => self::allTones(),
            'activeTones' => $round['tones'],
            'tone' => $round['current'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'finished' => $round['finished'],
            'promotable' => $round['finished'] && !($round['fromPlan'] ?? false),
            'returnRouteId' => $round['returnRouteId'] ?? null,
            'autoAdvanceSeconds' => $round['autoAdvanceSeconds'],
            'startedAtMs' => (int) round($round['startedAt'] * 1000),
            'elapsedLabel' => $this->formatElapsed(microtime(true) - $round['startedAt']),
            'mode' => $mode,
            'scaleLabel' => $mode === 'scale' && $scaleId !== null ? Scale::get($scaleId)->label() : null,
            'csrfIntention' => self::CSRF_INTENTION,
            'hide_footer' => true,
            'hideChromeOnMobile' => true,
            'hidePracticeTools' => true,
        ];
    }

    private function formatElapsed(float $seconds): string
    {
        $whole = max(0, (int) round($seconds));

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
