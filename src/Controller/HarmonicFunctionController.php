<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\WeightedQuestionPicker;
use App\Domain\Theory\MusicKey;
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
    private const MODES = ['chord', 'note', 'mixed'];

    public function __construct(private readonly QuizAttemptRepository $attempts)
    {
    }

    #[Route('', name: 'harmonic_function_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);

        return $this->render('harmonic_function/settings.html.twig', [
            'allKeys' => MusicKey::guitarPool(),
            'activeKeys' => $round !== null ? $round['keys'] : array_keys(MusicKey::guitarPool()),
            'mode' => $round !== null ? $round['mode'] : 'chord',
            'questionCount' => $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT,
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

        $mode = $request->request->get('mode', 'chord');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'chord';
        }

        $questionCount = max(
            self::MIN_QUESTION_COUNT,
            min(self::MAX_QUESTION_COUNT, (int) $request->request->get('question_count', self::DEFAULT_QUESTION_COUNT)),
        );

        $this->beginRound($request, $selected, $mode, $questionCount);

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
    public function answer(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('harmonic_function_settings');
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
                'guess' => $guess,
                'roman' => $current['roman'],
                'keyId' => $current['keyId'],
                'itemLabel' => $current['itemLabel'],
            ];
        }

        if ($round['questionIndex'] >= $round['totalQuestions']) {
            $round['finished'] = true;
            $round['current'] = null;
            $session->set(self::SESSION_ROUND, $round);
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
        $mode = $round !== null ? $round['mode'] : 'chord';
        $questionCount = $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT;

        $this->beginRound($request, $keys, $mode, $questionCount);

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
     * Reads the round from the session, discarding it if missing or from
     * an incompatible older schema.
     *
     * @return array{keys: string[], mode: string, totalQuestions: int, questionIndex: int, correctCount: int, finished: bool, current: array{questionKey: string, keyId: string, degree: int, itemType: string, itemLabel: string, roman: string}|null, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['keys'], $round['mode'], $round['totalQuestions'], $round['questionIndex'], $round['correctCount'], $round['finished'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /** @param string[] $keys */
    private function beginRound(Request $request, array $keys, string $mode, int $questionCount): void
    {
        $request->getSession()->set(self::SESSION_ROUND, [
            'keys' => $keys,
            'mode' => $mode,
            'totalQuestions' => $questionCount,
            'questionIndex' => 1,
            'correctCount' => 0,
            'finished' => false,
            'current' => null,
            'startedAt' => microtime(true),
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

        $itemType = $round['mode'] === 'mixed'
            ? (random_int(0, 1) === 0 ? 'chord' : 'note')
            : $round['mode'];
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
     * @param array{correct: bool, guess: string, roman: string, keyId: string, itemLabel: string}|null $toast
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
            'allKeys' => MusicKey::guitarPool(),
            'activeKeys' => $round['keys'],
            'mode' => $round['mode'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'correctCount' => $round['correctCount'],
            'finished' => $round['finished'],
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
