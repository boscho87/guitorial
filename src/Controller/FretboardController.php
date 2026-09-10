<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\AnswerOptionPicker;
use App\Domain\Quiz\WeightedQuestionPicker;
use App\Domain\Session\ExercisePlanLink;
use App\Domain\Session\SessionRecorder;
use App\Domain\Session\ToolConfigSnapshot;
use App\Domain\Theory\GuitarString;
use App\Domain\Theory\Note;
use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use App\Repository\PracticeSessionRepository;
use App\Repository\QuizAttemptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Which note is on string X at fret Y" and its two inverses - any subset
 * of the three directions can be active at once, chosen fresh per
 * question. Strings 1 and 6 share every open note, so grading always
 * recomputes the note from the guess (noteAt()) rather than comparing to
 * a single stored "expected" value - otherwise a correct answer on the
 * "other" E string would be marked wrong.
 */
#[Route('/griffbrett')]
final class FretboardController extends AbstractController
{
    private const TOOL = 'griffbrett';
    private const SESSION_ROUND = 'fretboard.round';
    private const CSRF_INTENTION = 'fretboard';
    private const DEFAULT_QUESTION_COUNT = 10;
    private const MIN_QUESTION_COUNT = 3;
    private const MAX_QUESTION_COUNT = 30;
    private const DEFAULT_MAX_FRET = 12;
    private const MODES = ['note', 'string', 'fret'];

    public function __construct(
        private readonly QuizAttemptRepository $attempts,
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExercisePlanLink $planLink,
    ) {
    }

    #[Route('', name: 'fretboard_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);
        $plan = $this->resolvePlanLink($request, $request->query->get('uebung'), $request->query->get('ressource'));

        return $this->render('fretboard/settings.html.twig', [
            'allStrings' => GuitarString::all(),
            'activeStrings' => $round !== null ? $round['strings'] : array_keys(GuitarString::all()),
            'activeModes' => $round !== null ? $round['modes'] : self::MODES,
            'maxFret' => $round !== null ? $round['maxFret'] : self::DEFAULT_MAX_FRET,
            'questionCount' => $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT,
            'planExerciseId' => $plan !== null ? $plan['exercise']->getId() : null,
            'planResourceId' => $plan !== null ? $plan['resource']->getId() : null,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'fretboard_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $strings = array_values(array_intersect(
            $request->request->all('strings'),
            array_keys(GuitarString::all()),
        ));
        if ($strings === []) {
            $strings = array_keys(GuitarString::all());
        }

        $modes = array_values(array_intersect($request->request->all('modes'), self::MODES));
        if ($modes === []) {
            $modes = self::MODES;
        }

        $maxFret = max(1, min(24, (int) $request->request->get('max_fret', self::DEFAULT_MAX_FRET)));

        $questionCount = max(
            self::MIN_QUESTION_COUNT,
            min(self::MAX_QUESTION_COUNT, (int) $request->request->get('question_count', self::DEFAULT_QUESTION_COUNT)),
        );

        $plan = $this->resolvePlanLink($request, $request->request->get('uebung'), $request->request->get('ressource'));
        $this->beginRound($request, $strings, $modes, $maxFret, $questionCount, $plan['exercise'] ?? null, $plan['resource'] ?? null);

        return $this->redirectToRoute('fretboard_practice');
    }

    #[Route('/uebung', name: 'fretboard_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        if ($this->currentRound($request) === null) {
            return $this->redirectToRoute('fretboard_settings');
        }

        $this->ensureCurrentQuestion($request);

        return $this->render('fretboard/practice.html.twig', $this->viewData($request));
    }

    /**
     * Grades the answer and immediately advances to the next question (or
     * the summary) in the same response, result shown as a toast.
     */
    #[Route('/antwort', name: 'fretboard_answer', methods: ['POST'])]
    public function answer(Request $request, PracticeSessionRepository $sessions): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('fretboard_settings');
        }

        $practiceSession = $sessions->find($round['practiceSessionId']);
        if ($practiceSession !== null) {
            $this->sessionRecorder->markInteraction($practiceSession);
        }

        $current = $round['current'];
        $guess = (string) $request->request->get('guess');
        $isCorrect = $current !== null && $this->isCorrectGuess($guess, $current);

        $toast = null;
        if ($current !== null) {
            $questionKey = $current['mode'] . ':' . $current['stringNumber'] . ':' . $current['fret'];
            $this->attempts->record(self::TOOL, $questionKey, $isCorrect);
            if ($isCorrect) {
                $round['correctCount']++;
            }
            $toast = [
                'correct' => $isCorrect,
                'guessLabel' => $current['mode'] === 'string' && $guess !== '' && array_key_exists($guess, GuitarString::all())
                    ? GuitarString::get($guess)->label()
                    : $guess,
                'answerLabel' => match ($current['mode']) {
                    'string' => GuitarString::get($current['stringNumber'])->label(),
                    'fret' => (string) $current['fret'],
                    default => $current['note'],
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

        return $this->render('fretboard/practice.html.twig', $this->viewData($request, $toast));
    }

    #[Route('/neue-runde', name: 'fretboard_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $strings = $round !== null ? $round['strings'] : array_keys(GuitarString::all());
        $modes = $round !== null ? $round['modes'] : self::MODES;
        $maxFret = $round !== null ? $round['maxFret'] : self::DEFAULT_MAX_FRET;
        $questionCount = $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT;

        $this->beginRound($request, $strings, $modes, $maxFret, $questionCount);

        return $this->redirectToRoute('fretboard_practice');
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
     * @param array{mode: string, stringNumber: string, fret: int, note: string} $current
     */
    private function isCorrectGuess(string $guess, array $current): bool
    {
        return match ($current['mode']) {
            'note' => $guess === $current['note'],
            'string' => $guess !== '' && GuitarString::get($guess)->noteAt($current['fret']) === $current['note'],
            'fret' => is_numeric($guess) && GuitarString::get($current['stringNumber'])->noteAt((int) $guess) === $current['note'],
            default => false,
        };
    }

    /**
     * Reads the round from the session, discarding it if missing or from
     * an incompatible older schema.
     *
     * @return array{strings: string[], modes: string[], maxFret: int, totalQuestions: int, questionIndex: int, correctCount: int, finished: bool, current: array{mode: string, stringNumber: string, fret: int, note: string}|null, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['strings'], $round['modes'], $round['maxFret'], $round['totalQuestions'], $round['questionIndex'], $round['correctCount'], $round['finished'], $round['practiceSessionId'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $strings
     * @param string[] $modes
     */
    private function beginRound(Request $request, array $strings, array $modes, int $maxFret, int $questionCount, ?Exercise $exercise = null, ?ExerciseResource $resource = null): void
    {
        $visitorId = $request->attributes->get('visitorId');
        $practiceSession = $this->sessionRecorder->start(
            is_string($visitorId) ? $visitorId : '',
            self::TOOL,
            exercise: $exercise,
            resource: $resource,
            configSnapshot: ToolConfigSnapshot::fretboard($strings, $modes, $maxFret, $questionCount),
        );

        $request->getSession()->set(self::SESSION_ROUND, [
            'strings' => $strings,
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

    /**
     * Strings 1 and 6 are both tuned to E, so they sound identical at
     * every fret. That's fine for "note" (either gives the same, correct
     * note) and "fret" (asking on one specific string is still
     * meaningful), but "which string is this?" has no single right answer
     * when both are active - collapse them to just "6" for that mode, so
     * every question has exactly one clear answer.
     *
     * @param string[] $strings
     * @return string[]
     */
    private function stringPoolFor(string $mode, array $strings): array
    {
        if ($mode === 'string' && in_array('1', $strings, true) && in_array('6', $strings, true)) {
            return array_values(array_diff($strings, ['1']));
        }

        return $strings;
    }

    private function ensureCurrentQuestion(Request $request): void
    {
        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null || $round['current'] !== null) {
            return;
        }

        // Pick which direction to ask first (uniformly - it's not really a
        // "difficulty" axis), then weight the string/fret position within
        // that direction's own history.
        $mode = $round['modes'][array_rand($round['modes'])];

        $strings = $this->stringPoolFor($mode, $round['strings']);

        $candidates = [];
        foreach ($strings as $stringNumber) {
            for ($fret = 0; $fret <= $round['maxFret']; $fret++) {
                $candidates[] = $stringNumber . ':' . $fret;
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
        [$stringNumber, $fretText] = explode(':', $picked, 2);
        $fret = (int) $fretText;

        $round['current'] = [
            'mode' => $mode,
            'stringNumber' => $stringNumber,
            'fret' => $fret,
            'note' => GuitarString::get($stringNumber)->noteAt($fret),
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

        // {value, label}: the submitted value the grader understands, and
        // what the button shows - identical for note/fret, but "string"
        // mode submits the bare number while showing e.g. "6 (E)".
        $allOptions = match ($mode) {
            'note' => array_map(
                static fn (int $i) => ['value' => Note::nameFromIndex($i), 'label' => Note::nameFromIndex($i)],
                range(0, 11),
            ),
            'string' => array_map(
                static fn (string $number) => ['value' => $number, 'label' => GuitarString::get($number)->label()],
                $this->stringPoolFor($mode, $round['strings']),
            ),
            'fret' => array_map(
                static fn (int $f) => ['value' => (string) $f, 'label' => (string) $f],
                range(0, $round['maxFret']),
            ),
            default => [],
        };

        // Cap at 10 choices (always including the correct one) so every
        // button can carry a 1-9/0 keyboard shortcut, even when the full
        // pool (e.g. all 12 chromatic notes, or a wide fret range) is bigger.
        $correctValue = match ($mode) {
            'note' => $current['note'] ?? null,
            'string' => $current['stringNumber'] ?? null,
            'fret' => isset($current['fret']) ? (string) $current['fret'] : null,
            default => null,
        };
        if ($correctValue !== null) {
            $byValue = array_column($allOptions, null, 'value');
            $pickedValues = AnswerOptionPicker::pick(array_keys($byValue), $correctValue);
            $answerOptions = array_map(static fn (string $v) => $byValue[$v], $pickedValues);
        } else {
            $answerOptions = $allOptions;
        }

        return [
            'sessionId' => $round['practiceSessionId'],
            'allStrings' => GuitarString::all(),
            'activeStrings' => $round['strings'],
            'activeModes' => $round['modes'],
            'mode' => $mode,
            'maxFret' => $round['maxFret'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'correctCount' => $round['correctCount'],
            'finished' => $round['finished'],
            'promotable' => $round['finished'] && !($round['fromPlan'] ?? false),
            'stringLabel' => $current !== null ? GuitarString::get($current['stringNumber'])->label() : null,
            'fret' => $current['fret'] ?? null,
            'note' => $current['note'] ?? null,
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
