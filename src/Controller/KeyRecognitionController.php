<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\AnswerOptionPicker;
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
 * A "pair" here is a relative major/minor signature group (e.g. C-Dur/Am -
 * same notes, same key signature). Since a chord progression alone can't
 * reliably tell you which of the two you're "in", the exercise asks for
 * the pair (labelled by its major name), not a specific major-or-minor
 * tonic - but still generates the progression from either variant for
 * variety, and shows the shared chords/signature in the feedback.
 */
#[Route('/tonart-erkennen')]
final class KeyRecognitionController extends AbstractController
{
    private const TOOL = 'tonart-erkennen';
    private const SESSION_ROUND = 'key_recognition.round';
    private const CSRF_INTENTION = 'key_recognition';
    private const DEFAULT_QUESTION_COUNT = 10;
    private const MIN_QUESTION_COUNT = 3;
    private const MAX_QUESTION_COUNT = 30;

    public function __construct(
        private readonly QuizAttemptRepository $attempts,
        private readonly SessionRecorder $sessionRecorder,
        private readonly ExercisePlanLink $planLink,
    ) {
    }

    #[Route('', name: 'key_recognition_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $pairs = MusicKey::signaturePairs();
        $round = $this->currentRound($request);
        $activePairs = $round !== null ? $round['pairs'] : array_column($pairs, 'majorId');
        $plan = $this->resolvePlanLink($request, $request->query->get('uebung'), $request->query->get('ressource'));

        return $this->render('key_recognition/settings.html.twig', [
            'pairs' => $pairs,
            'activePairs' => $activePairs,
            'questionCount' => $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT,
            'planExerciseId' => $plan !== null ? $plan['exercise']->getId() : null,
            'planResourceId' => $plan !== null ? $plan['resource']->getId() : null,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'key_recognition_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $selected = array_values(array_intersect(
            $request->request->all('pairs'),
            array_column(MusicKey::signaturePairs(), 'majorId'),
        ));
        if ($selected === []) {
            $selected = array_column(MusicKey::signaturePairs(), 'majorId');
        }

        $questionCount = max(
            self::MIN_QUESTION_COUNT,
            min(self::MAX_QUESTION_COUNT, (int) $request->request->get('question_count', self::DEFAULT_QUESTION_COUNT)),
        );

        $plan = $this->resolvePlanLink($request, $request->request->get('uebung'), $request->request->get('ressource'));
        $this->beginRound($request, $selected, $questionCount, $plan['exercise'] ?? null, $plan['resource'] ?? null);

        return $this->redirectToRoute('key_recognition_practice');
    }

    #[Route('/uebung', name: 'key_recognition_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        if ($this->currentRound($request) === null) {
            return $this->redirectToRoute('key_recognition_settings');
        }

        $this->ensureCurrentQuestion($request);

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request));
    }

    /**
     * Grades the answer and immediately advances to the next question (or
     * the summary) in the same response - the result is shown as a toast
     * alongside the new question rather than as a separate click-through
     * step, so practicing stays a single tap per question.
     */
    #[Route('/antwort', name: 'key_recognition_answer', methods: ['POST'])]
    public function answer(Request $request, PracticeSessionRepository $sessions): Response
    {
        $this->assertValidCsrf($request);

        $session = $request->getSession();
        $round = $this->currentRound($request);
        if ($round === null) {
            return $this->redirectToRoute('key_recognition_settings');
        }

        $practiceSession = $sessions->find($round['practiceSessionId']);
        if ($practiceSession !== null) {
            $this->sessionRecorder->markInteraction($practiceSession);
        }

        $correctPairId = $round['current']['pairId'] ?? null;
        $guess = (string) $request->request->get('guess');
        $isCorrect = $correctPairId !== null && $guess === $correctPairId;

        $toast = null;
        if ($correctPairId !== null) {
            $this->attempts->record(self::TOOL, $correctPairId, $isCorrect);
            if ($isCorrect) {
                $round['correctCount']++;
            }
            $toast = [
                'correct' => $isCorrect,
                'guessLabel' => $this->pairLabel($guess),
                'answerLabel' => $this->pairLabel($correctPairId),
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

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request, $toast));
    }

    #[Route('/neue-runde', name: 'key_recognition_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $pairs = $round !== null ? $round['pairs'] : array_column(MusicKey::signaturePairs(), 'majorId');
        $questionCount = $round !== null ? $round['totalQuestions'] : self::DEFAULT_QUESTION_COUNT;

        $this->beginRound($request, $pairs, $questionCount);

        return $this->redirectToRoute('key_recognition_practice');
    }

    /**
     * Reads the round from the session, discarding it if it's missing or
     * from an older, incompatible session schema (e.g. after a deploy).
     *
     * @return array{pairs: string[], totalQuestions: int, questionIndex: int, correctCount: int, finished: bool, current: array{pairId: string, progression: string[]}|null, practiceSessionId: int}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['pairs'], $round['totalQuestions'], $round['questionIndex'], $round['correctCount'], $round['finished'], $round['practiceSessionId'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
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

    /** @param string[] $pairs */
    private function beginRound(Request $request, array $pairs, int $questionCount, ?Exercise $exercise = null, ?ExerciseResource $resource = null): void
    {
        $visitorId = $request->attributes->get('visitorId');
        $practiceSession = $this->sessionRecorder->start(
            is_string($visitorId) ? $visitorId : '',
            self::TOOL,
            exercise: $exercise,
            resource: $resource,
            configSnapshot: ToolConfigSnapshot::keyRecognition($pairs, $questionCount),
        );

        $request->getSession()->set(self::SESSION_ROUND, [
            'pairs' => $pairs,
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

        $wrongCounts = $this->attempts->wrongCountsByQuestionKey(self::TOOL);
        $pairId = WeightedQuestionPicker::pick($round['pairs'], $wrongCounts);

        $pair = null;
        foreach (MusicKey::signaturePairs() as $candidate) {
            if ($candidate['majorId'] === $pairId) {
                $pair = $candidate;
                break;
            }
        }

        // Randomly generate from the major or minor variant for variety -
        // the answer is the pair either way, so it doesn't change what's tested.
        $variantId = random_int(0, 1) === 0 ? $pair['majorId'] : $pair['minorId'];
        $variant = MusicKey::get($variantId);

        // Neighbouring keys on the circle of fifths share most of their
        // chords (e.g. C, Am, Em, G are diatonic to *both* C-Dur and
        // G-Dur) - compare against every other selectable pair so the
        // generated progression can't accidentally fit one of them too.
        $otherKeys = [];
        foreach (MusicKey::signaturePairs() as $candidate) {
            if ($candidate['majorId'] === $pairId || !in_array($candidate['majorId'], $round['pairs'], true)) {
                continue;
            }
            $otherKeys[] = MusicKey::get($candidate['majorId']);
            $otherKeys[] = MusicKey::get($candidate['minorId']);
        }

        $round['current'] = [
            'pairId' => $pairId,
            'progression' => $this->buildProgression($variant, $otherKeys),
        ];
        $session->set(self::SESSION_ROUND, $round);
    }

    /**
     * @param array{correct: bool, guessLabel: string, answerLabel: string}|null $toast result of the answer
     *        that led to this render, shown as a transient toast - null on a plain page load
     * @return array<string, mixed>
     */
    private function viewData(Request $request, ?array $toast = null): array
    {
        $round = $this->currentRound($request);
        $startedAt = $round['startedAt'] ?? microtime(true);
        $correctPairId = $round['current']['pairId'] ?? null;
        $optionIds = $correctPairId !== null
            ? AnswerOptionPicker::pick($round['pairs'], $correctPairId)
            : [];

        return [
            'sessionId' => $round['practiceSessionId'],
            'answerOptions' => array_map(
                fn (string $id): array => ['id' => $id, 'label' => $this->pairLabel($id)],
                $optionIds,
            ),
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'correctCount' => $round['correctCount'],
            'finished' => $round['finished'],
            'promotable' => $round['finished'] && !($round['fromPlan'] ?? false),
            'progression' => $round['current']['progression'] ?? null,
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

    private function pairLabel(string $majorId): string
    {
        foreach (MusicKey::signaturePairs() as $pair) {
            if ($pair['majorId'] === $majorId) {
                return $pair['label'];
            }
        }

        return $majorId;
    }

    /**
     * @param MusicKey[] $otherKeys every other selectable key/pair variant in
     *        this round - the progression must not be fully explainable by
     *        one of them too (see the comment in ensureCurrentQuestion())
     * @return string[]
     */
    private function buildProgression(MusicKey $key, array $otherKeys): array
    {
        $chords = $key->diatonicChords();
        $namesByDegree = array_column($chords, 'name', 'degree');

        // The diminished degree (vii° in major, ii° in minor) occurs on
        // exactly one degree of the scale, so seeing it instantly gives
        // away the key without needing to read the rest of the
        // progression - excluded from the pool the other 3 chords are
        // drawn from (the tonic itself is never diminished).
        $diminishedDegrees = array_column(
            array_filter($chords, static fn (array $c): bool => $c['quality'] === 'dim'),
            'degree',
        );
        $allDegrees = array_diff(array_keys($namesByDegree), $diminishedDegrees);

        $fallback = null;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $selectedDegrees = [1];
            $rest = array_values(array_diff($allDegrees, [1]));
            shuffle($rest);
            foreach (array_slice($rest, 0, 3) as $degree) {
                $selectedDegrees[] = $degree;
            }
            shuffle($selectedDegrees);

            $names = array_map(static fn (int $degree) => $namesByDegree[$degree], $selectedDegrees);
            $fallback ??= $names;

            if (!$this->fitsAnyOtherKey($names, $otherKeys)) {
                return $names;
            }
        }

        // Every attempt was ambiguous (e.g. only 1-2 very similar keys are
        // active) - fall back to the last try rather than looping forever.
        return $fallback;
    }

    /** @param MusicKey[] $otherKeys */
    private function fitsAnyOtherKey(array $chordNames, array $otherKeys): bool
    {
        foreach ($otherKeys as $other) {
            $otherNames = array_column($other->diatonicChords(), 'name');
            if (array_diff($chordNames, $otherNames) === []) {
                return true;
            }
        }

        return false;
    }
}
