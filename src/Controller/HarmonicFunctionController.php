<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\WeightedQuestionPicker;
use App\Domain\Session\ExercisePlanLink;
use App\Domain\Session\SessionRecorder;
use App\Domain\Session\ToolConfigSnapshot;
use App\Domain\Theory\MusicKey;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Repository\PracticeSessionRepository;
use App\Repository\QuizAttemptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Which scale degree is this?" - e.g. F#m is the vi of A-Dur. Unlike
 * Tonart erkennen, major and minor can't be collapsed into one pair here:
 * the same chord/note is a different degree depending on which specific
 * key (major or minor) it's read against, so the key is always given.
 */
#[Route('/funktion')]
final class HarmonicFunctionController extends AbstractController
{
    private const TOOL = 'funktion-bestimmen';
    private const SESSION_ROUND = 'harmonic_function.round';
    private const CSRF_INTENTION = 'harmonic_function';
    private const DEFAULT_QUESTION_COUNT = 10;
    private const MIN_QUESTION_COUNT = 3;
    private const MAX_QUESTION_COUNT = 30;
    private const ITEM_TYPES = ['chord', 'note'];

    public function __construct(
        private readonly QuizAttemptRepository $attempts,
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExercisePlanLink $planLink,
    ) {
    }

    #[Route('', name: 'harmonic_function_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);
        $plan = $this->resolvePlanLink($request, $request->query->get('uebung'), $request->query->get('ressource'));

        return $this->render('harmonic_function/settings.html.twig', [
            'allKeys' => MusicKey::guitarPool(),
            'activeKeys' => $round !== null ? $round['keys'] : array_keys(MusicKey::guitarPool()),
            'activeItemTypes' => $round !== null ? $round['itemTypes'] : self::ITEM_TYPES,
            'questionCount' => $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT,
            'planExerciseId' => $plan !== null ? $plan['exercise']->getId() : null,
            'planResourceId' => $plan !== null ? $plan['resource']->getId() : null,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'harmonic_function_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $selected = array_values(array_intersect(
            $request->request->all('keys'),
            array_keys(MusicKey::guitarPool()),
        ));
        if ($selected === []) {
            $selected = array_keys(MusicKey::guitarPool());
        }

        $itemTypes = array_values(array_intersect($request->request->all('item_types'), self::ITEM_TYPES));
        if ($itemTypes === []) {
            $itemTypes = self::ITEM_TYPES;
        }

        $questionCount = max(
            self::MIN_QUESTION_COUNT,
            min(self::MAX_QUESTION_COUNT, (int) $request->request->get('question_count', self::DEFAULT_QUESTION_COUNT)),
        );

        $plan = $this->resolvePlanLink($request, $request->request->get('uebung'), $request->request->get('ressource'));
        $this->beginRound($request, $selected, $itemTypes, $questionCount, $plan['exercise'] ?? null, $plan['resource'] ?? null);

        return $this->redirectToRoute('harmonic_function_practice');
    }

    #[Route('/uebung', name: 'harmonic_function_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        if ($this->currentRound($request) === null) {
            return $this->redirectToRoute('harmonic_function_settings');
        }

        $this->ensureCurrentQuestion($request);

        return $this->render('harmonic_function/practice.html.twig', $this->viewData($request));
    }

    /**
     * Grades the answer and immediately advances to the next question (or
     * the summary) in the same response, result shown as a toast.
     */
    #[Route('/antwort', name: 'harmonic_function_answer', methods: ['POST'])]
    public function answer(Request $request, PracticeSessionRepository $sessions): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('harmonic_function_settings');
        }

        $practiceSession = $sessions->find($round['practiceSessionId']);
        if ($practiceSession !== null) {
            $this->sessionRecorder->markInteraction($practiceSession);
        }

        $current = $round['current'];
        $guess = (string) $request->request->get('guess');
        $isCorrect = $current !== null && $guess === $current['roman'];

        $toast = null;
        if ($current !== null) {
            $this->attempts->record(self::TOOL, $current['questionKey'], $isCorrect);
            if ($isCorrect) {
                $round['correctCount']++;
            }
            $toast = [
                'correct' => $isCorrect,
                'guessLabel' => $guess,
                'answerLabel' => $current['roman'],
            ];
        }

        if ($round['questionIndex'] >= $round['totalQuestions']) {
            $round['finished'] = true;
            $round['current'] = null;
            $session->set(self::SESSION_ROUND, $round);
            if ($practiceSession !== null) {
                $this->sessionRecorder->finish($practiceSession);
            }
        } else {
            $round['questionIndex']++;
            $round['current'] = null;
            $session->set(self::SESSION_ROUND, $round);
            $this->ensureCurrentQuestion($request);
        }

        return $this->render('harmonic_function/practice.html.twig', $this->viewData($request, $toast));
    }

    #[Route('/neue-runde', name: 'harmonic_function_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $keys = $round !== null ? $round['keys'] : array_keys(MusicKey::guitarPool());
        $itemTypes = $round !== null ? $round['itemTypes'] : self::ITEM_TYPES;
        $questionCount = $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT;

        $this->beginRound($request, $keys, $itemTypes, $questionCount);

        return $this->redirectToRoute('harmonic_function_practice');
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
     * Reads the round from the session, discarding it if missing or from
     * an incompatible older schema.
     *
     * @return array{keys: string[], itemTypes: string[], totalQuestions: int, questionIndex: int, correctCount: int, finished: bool, current: array{questionKey: string, keyId: string, degree: int, itemType: string, itemLabel: string, roman: string}|null, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['keys'], $round['itemTypes'], $round['totalQuestions'], $round['questionIndex'], $round['correctCount'], $round['finished'], $round['practiceSessionId'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $keys
     * @param string[] $itemTypes
     */
    private function beginRound(Request $request, array $keys, array $itemTypes, int $questionCount, ?Exercise $exercise = null, ?ExerciseResource $resource = null): void
    {
        $visitorId = $request->attributes->get('visitorId');
        $practiceSession = $this->sessionRecorder->start(
            is_string($visitorId) ? $visitorId : '',
            self::TOOL,
            exercise: $exercise,
            resource: $resource,
            configSnapshot: ToolConfigSnapshot::harmonicFunction($keys, $itemTypes, $questionCount),
        );

        $request->getSession()->set(self::SESSION_ROUND, [
            'keys' => $keys,
            'itemTypes' => $itemTypes,
            'totalQuestions' => $questionCount,
            'questionIndex' => 1,
            'correctCount' => 0,
            'finished' => false,
            'current' => null,
            'startedAt' => microtime(true),
            'practiceSessionId' => $practiceSession->getId(),
            'fromPlan' => $exercise !== null,
        ]);
        $this->ensureCurrentQuestion($request);
    }

    private function ensureCurrentQuestion(Request $request): void
    {
        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null || $round['current'] !== null) {
            return;
        }

        $candidates = [];
        foreach ($round['keys'] as $keyId) {
            for ($degree = 1; $degree <= 7; $degree++) {
                $candidates[] = $keyId . ':' . $degree;
            }
        }

        $wrongCounts = $this->attempts->wrongCountsByQuestionKey(self::TOOL);
        $questionKey = WeightedQuestionPicker::pick($candidates, $wrongCounts);
        [$keyId, $degreeText] = explode(':', $questionKey, 2);
        $degree = (int) $degreeText;

        $chord = MusicKey::get($keyId)->diatonicChords()[$degree - 1];

        $itemType = $round['itemTypes'][array_rand($round['itemTypes'])];
        $itemLabel = $itemType === 'chord' ? $chord['name'] : $chord['note'];

        $round['current'] = [
            'questionKey' => $questionKey,
            'keyId' => $keyId,
            'degree' => $degree,
            'itemType' => $itemType,
            'itemLabel' => $itemLabel,
            'roman' => $chord['roman'],
        ];
        $session->set(self::SESSION_ROUND, $round);
    }

    /**
     * @param array{correct: bool, guessLabel: string, answerLabel: string}|null $toast
     * @return array<string, mixed>
     */
    private function viewData(Request $request, ?array $toast = null): array
    {
        $round = $this->currentRound($request);
        $startedAt = $round['startedAt'] ?? microtime(true);
        $current = $round['current'];

        $romanOptions = [];
        if ($current !== null) {
            $romanOptions = array_column(MusicKey::get($current['keyId'])->diatonicChords(), 'roman');
        }

        return [
            'sessionId' => $round['practiceSessionId'],
            'allKeys' => MusicKey::guitarPool(),
            'activeKeys' => $round['keys'],
            'activeItemTypes' => $round['itemTypes'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'correctCount' => $round['correctCount'],
            'finished' => $round['finished'],
            'promotable' => $round['finished'] && !($round['fromPlan'] ?? false),
            'keyId' => $current['keyId'] ?? null,
            'itemLabel' => $current['itemLabel'] ?? null,
            'itemType' => $current['itemType'] ?? null,
            'romanOptions' => $romanOptions,
            'toast' => $toast,
            'startedAtMs' => (int) round($startedAt * 1000),
            'elapsedLabel' => $this->formatElapsed(microtime(true) - $startedAt),
            'csrfIntention' => self::CSRF_INTENTION,
        ];
    }

    private function formatElapsed(float $seconds): string
    {
        $whole = max(0, (int) round($seconds));

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
