<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\AnswerOptionPicker;
use App\Domain\Quiz\WeightedQuestionPicker;
use App\Domain\Session\ExercisePlanLink;
use App\Domain\Session\ExerciseTargetProgress;
use App\Domain\Session\PersonalRecords;
use App\Domain\Session\SessionRecorder;
use App\Domain\Session\ToolConfigSnapshot;
use App\Domain\Theory\CapoShape;
use App\Domain\Theory\CapoTransposer;
use App\Domain\Theory\Note;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Repository\PracticeSessionRepository;
use App\Repository\QuizAttemptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Grading always recomputes the sounding chord from the guess rather than
 * comparing to a single stored "expected" value, so it stays correct
 * regardless of which direction was asked.
 */
#[Route('/kapo-trainer')]
final class CapoTrainerController extends AbstractController
{
    private const TOOL = 'kapo-trainer';
    private const SESSION_ROUND = 'capo_trainer.round';
    private const CSRF_INTENTION = 'capo_trainer';
    private const DEFAULT_QUESTION_COUNT = 10;
    private const MIN_QUESTION_COUNT = 3;
    private const MAX_QUESTION_COUNT = 30;
    private const DEFAULT_MAX_FRET = 7;
    private const MODES = ['1', '2', '3'];

    public function __construct(
        private readonly QuizAttemptRepository $attempts,
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExercisePlanLink $planLink,
        private readonly PracticeSessionRepository $sessions,
        private readonly ExerciseTargetProgress $targetProgress,
        private readonly PersonalRecords $personalRecords,
    ) {
    }

    #[Route('', name: 'capo_trainer_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);
        $plan = $this->resolvePlanLink($request, $request->query->get('uebung'), $request->query->get('ressource'));

        return $this->render('capo_trainer/settings.html.twig', [
            'allShapes' => CapoShape::all(),
            'activeShapes' => $round !== null ? $round['shapes'] : array_keys(CapoShape::all()),
            'activeModes' => $round !== null ? $round['modes'] : self::MODES,
            'maxFret' => $round !== null ? $round['maxFret'] : self::DEFAULT_MAX_FRET,
            'questionCount' => $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT,
            'planExerciseId' => $plan !== null ? $plan['exercise']->getId() : null,
            'planResourceId' => $plan !== null ? $plan['resource']->getId() : null,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'capo_trainer_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $shapes = array_values(array_intersect(
            $request->request->all('shapes'),
            array_keys(CapoShape::all()),
        ));
        if ($shapes === []) {
            $shapes = array_keys(CapoShape::all());
        }

        $modes = array_values(array_intersect($request->request->all('modes'), self::MODES));
        if ($modes === []) {
            $modes = self::MODES;
        }

        $maxFret = max(1, min(11, (int) $request->request->get('max_fret', self::DEFAULT_MAX_FRET)));

        $questionCount = max(
            self::MIN_QUESTION_COUNT,
            min(self::MAX_QUESTION_COUNT, (int) $request->request->get('question_count', self::DEFAULT_QUESTION_COUNT)),
        );

        $plan = $this->resolvePlanLink($request, $request->request->get('uebung'), $request->request->get('ressource'));
        $this->beginRound($request, $shapes, $modes, $maxFret, $questionCount, $plan['exercise'] ?? null, $plan['resource'] ?? null);

        return $this->redirectToRoute('capo_trainer_practice');
    }

    #[Route('/uebung', name: 'capo_trainer_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        if ($this->currentRound($request) === null) {
            return $this->redirectToRoute('capo_trainer_settings');
        }

        $this->ensureCurrentQuestion($request);

        return $this->render('capo_trainer/practice.html.twig', $this->viewData($request));
    }

    /**
     * Grades the answer and immediately advances to the next question (or
     * the summary) in the same response, result shown as a toast.
     */
    #[Route('/antwort', name: 'capo_trainer_answer', methods: ['POST'])]
    public function answer(Request $request, TranslatorInterface $translator): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('capo_trainer_settings');
        }

        $practiceSession = $this->sessions->find($round['practiceSessionId']);
        if ($practiceSession !== null) {
            $this->sessionRecorder->markInteraction($practiceSession);
        }

        $current = $round['current'];
        $guess = (string) $request->request->get('guess');
        $isCorrect = $current !== null && $this->isCorrectGuess($guess, $current);

        $toast = null;
        if ($current !== null) {
            $questionKey = $current['mode'] . ':' . $current['shapeId'] . ':' . $current['fret'];
            $this->attempts->record(self::TOOL, $questionKey, $isCorrect);
            if ($isCorrect) {
                $round['correctCount']++;
            }
            $toast = [
                'correct' => $isCorrect,
                'guessLabel' => $current['mode'] === '3'
                    ? $translator->trans('capo_trainer.practice.fret_answer', ['%fret%' => $guess])
                    : $guess,
                'answerLabel' => match ($current['mode']) {
                    '1' => $current['sounding'],
                    '2' => $current['shapeId'],
                    default => $translator->trans('capo_trainer.practice.fret_answer', ['%fret%' => $current['fret']]),
                },
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

        return $this->render('capo_trainer/practice.html.twig', $this->viewData($request, $toast));
    }

    #[Route('/neue-runde', name: 'capo_trainer_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $shapes = $round !== null ? $round['shapes'] : array_keys(CapoShape::all());
        $modes = $round !== null ? $round['modes'] : self::MODES;
        $maxFret = $round !== null ? $round['maxFret'] : self::DEFAULT_MAX_FRET;
        $questionCount = $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT;

        $this->beginRound($request, $shapes, $modes, $maxFret, $questionCount);

        return $this->redirectToRoute('capo_trainer_practice');
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
     * @param array{mode: string, shapeId: string, fret: int, sounding: string} $current
     */
    private function isCorrectGuess(string $guess, array $current): bool
    {
        $shapeIds = array_keys(CapoShape::all());

        return match ($current['mode']) {
            '1' => $guess === $current['sounding'],
            '2' => in_array($guess, $shapeIds, true)
                && CapoTransposer::soundingChord(CapoShape::get($guess), $current['fret']) === $current['sounding'],
            '3' => is_numeric($guess)
                && CapoTransposer::soundingChord(CapoShape::get($current['shapeId']), (int) $guess) === $current['sounding'],
            default => false,
        };
    }

    /**
     * Reads the round from the session, discarding it if missing or from
     * an incompatible older schema.
     *
     * @return array{shapes: string[], modes: string[], maxFret: int, totalQuestions: int, questionIndex: int, correctCount: int, finished: bool, current: array{mode: string, shapeId: string, fret: int, sounding: string}|null, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['shapes'], $round['modes'], $round['maxFret'], $round['totalQuestions'], $round['questionIndex'], $round['correctCount'], $round['finished'], $round['practiceSessionId'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $shapes
     * @param string[] $modes
     */
    private function beginRound(Request $request, array $shapes, array $modes, int $maxFret, int $questionCount, ?Exercise $exercise = null, ?ExerciseResource $resource = null): void
    {
        $visitorId = $request->attributes->get('visitorId');
        $practiceSession = $this->sessionRecorder->start(
            is_string($visitorId) ? $visitorId : '',
            self::TOOL,
            exercise: $exercise,
            resource: $resource,
            configSnapshot: ToolConfigSnapshot::capoTrainer($shapes, $modes, $maxFret, $questionCount),
        );

        $request->getSession()->set(self::SESSION_ROUND, [
            'shapes' => $shapes,
            'modes' => $modes,
            'maxFret' => $maxFret,
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

        // Pick the direction first (uniformly), then weight the
        // shape/fret position within that direction's own history.
        $mode = $round['modes'][array_rand($round['modes'])];

        $candidates = [];
        foreach ($round['shapes'] as $shapeId) {
            for ($fret = 1; $fret <= $round['maxFret']; $fret++) {
                $candidates[] = $shapeId . ':' . $fret;
            }
        }

        $allWrongCounts = $this->attempts->wrongCountsByQuestionKey(self::TOOL);
        $prefix = $mode . ':';
        $wrongCounts = [];
        foreach ($allWrongCounts as $key => $count) {
            if (str_starts_with($key, $prefix)) {
                $wrongCounts[substr($key, strlen($prefix))] = $count;
            }
        }

        $picked = WeightedQuestionPicker::pick($candidates, $wrongCounts);
        [$shapeId, $fretText] = explode(':', $picked, 2);
        $fret = (int) $fretText;

        $round['current'] = [
            'mode' => $mode,
            'shapeId' => $shapeId,
            'fret' => $fret,
            'sounding' => CapoTransposer::soundingChord(CapoShape::get($shapeId), $fret),
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
        $mode = $current['mode'] ?? $round['modes'][0];
        $shape = $current !== null ? CapoShape::get($current['shapeId']) : null;

        $pool = match ($mode) {
            '1' => array_map(
                static fn (int $i) => Note::nameFromIndex($i) . ($shape?->isMinor ? 'm' : ''),
                range(0, 11),
            ),
            '2' => $round['shapes'],
            '3' => array_map('strval', range(1, $round['maxFret'])),
            default => [],
        };
        $correctAnswer = match ($mode) {
            '1' => $current['sounding'] ?? null,
            '2' => $current['shapeId'] ?? null,
            '3' => isset($current['fret']) ? (string) $current['fret'] : null,
            default => null,
        };
        $answerOptions = $correctAnswer !== null ? AnswerOptionPicker::pick($pool, $correctAnswer) : $pool;

        $practiceSession = $this->sessions->find($round['practiceSessionId']);
        $timeProgress = $practiceSession?->getExercise() !== null
            ? $this->targetProgress->forExercise($practiceSession->getExercise(), $practiceSession)
            : null;

        $records = null;
        if ($round['finished'] && $practiceSession !== null) {
            $visitorId = $request->attributes->get('visitorId');
            $result = $this->personalRecords->forTool(is_string($visitorId) ? $visitorId : '', self::TOOL, $practiceSession, $round['correctCount'], $round['totalQuestions']);
            $records = $result + [
                'fastestTimeLabel' => $result['fastestSeconds'] !== null ? $this->formatElapsed((float) $result['fastestSeconds']) : null,
            ];
        }

        return [
            'sessionId' => $round['practiceSessionId'],
            'timeProgress' => $timeProgress,
            'records' => $records,
            'allShapes' => CapoShape::all(),
            'activeShapes' => $round['shapes'],
            'activeModes' => $round['modes'],
            'mode' => $mode,
            'maxFret' => $round['maxFret'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'correctCount' => $round['correctCount'],
            'finished' => $round['finished'],
            'promotable' => $round['finished'] && !($round['fromPlan'] ?? false),
            'shapeId' => $current['shapeId'] ?? null,
            'fret' => $current['fret'] ?? null,
            'sounding' => $current['sounding'] ?? null,
            'answerOptions' => $answerOptions,
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
