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

#[Route('/tonart-erkennen')]
final class KeyRecognitionController extends AbstractController
{
    private const TOOL = 'tonart-erkennen';
    private const SESSION_KEYS = 'key_recognition.active_keys';
    private const SESSION_CURRENT = 'key_recognition.current';
    private const CSRF_INTENTION = 'key_recognition';

    public function __construct(private readonly QuizAttemptRepository $attempts)
    {
    }

    #[Route('', name: 'key_recognition_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        $activeKeys = $this->activeKeys($request);
        $this->ensureCurrentQuestion($request, $activeKeys);

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request, $activeKeys));
    }

    #[Route('/einstellungen', name: 'key_recognition_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $selected = array_values(array_intersect(
            $request->request->all('keys'),
            array_keys(MusicKey::guitarPool()),
        ));
        if ($selected === []) {
            $selected = array_keys(MusicKey::guitarPool());
        }

        $session = $request->getSession();
        $session->set(self::SESSION_KEYS, $selected);
        $session->remove(self::SESSION_CURRENT);

        $this->ensureCurrentQuestion($request, $selected);

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request, $selected));
    }

    #[Route('/antwort', name: 'key_recognition_answer', methods: ['POST'])]
    public function answer(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $activeKeys = $this->activeKeys($request);
        $session = $request->getSession();
        $current = $session->get(self::SESSION_CURRENT);

        $guess = (string) $request->request->get('guess');
        $correctId = is_array($current) ? ($current['keyId'] ?? null) : null;
        $isCorrect = $correctId !== null && $guess === $correctId;

        if ($correctId !== null && !($current['answered'] ?? false)) {
            $this->attempts->record(self::TOOL, $correctId, $isCorrect);
        }

        $current['answered'] = true;
        $current['guess'] = $guess;
        $current['correct'] = $isCorrect;
        $session->set(self::SESSION_CURRENT, $current);

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request, $activeKeys));
    }

    #[Route('/naechste', name: 'key_recognition_next', methods: ['POST'])]
    public function next(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $activeKeys = $this->activeKeys($request);
        $request->getSession()->remove(self::SESSION_CURRENT);
        $this->ensureCurrentQuestion($request, $activeKeys);

        return $this->render('key_recognition/practice.html.twig', $this->viewData($request, $activeKeys));
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @return string[] */
    private function activeKeys(Request $request): array
    {
        $session = $request->getSession();
        $stored = $session->get(self::SESSION_KEYS);
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        $all = array_keys(MusicKey::guitarPool());
        $session->set(self::SESSION_KEYS, $all);

        return $all;
    }

    /** @param string[] $activeKeys */
    private function ensureCurrentQuestion(Request $request, array $activeKeys): void
    {
        $session = $request->getSession();
        $current = $session->get(self::SESSION_CURRENT);
        if (is_array($current) && in_array($current['keyId'] ?? null, $activeKeys, true)) {
            return;
        }

        $wrongCounts = $this->attempts->wrongCountsByQuestionKey(self::TOOL);
        $keyId = WeightedQuestionPicker::pick($activeKeys, $wrongCounts);
        $key = MusicKey::get($keyId);

        $session->set(self::SESSION_CURRENT, [
            'keyId' => $keyId,
            'progression' => $this->buildProgression($key),
            'answered' => false,
            'guess' => null,
            'correct' => null,
        ]);
    }

    /**
     * @param string[] $activeKeys
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $activeKeys): array
    {
        $current = $request->getSession()->get(self::SESSION_CURRENT);
        $correctKey = MusicKey::get($current['keyId']);

        return [
            'allKeys' => MusicKey::guitarPool(),
            'activeKeys' => $activeKeys,
            'progression' => $current['progression'],
            'answered' => $current['answered'],
            'guess' => $current['guess'],
            'correct' => $current['correct'],
            'correctKey' => $correctKey,
            'summary' => $this->attempts->summary(self::TOOL),
            'csrfIntention' => self::CSRF_INTENTION,
        ];
    }

    /** @return string[] */
    private function buildProgression(MusicKey $key): array
    {
        $chords = $key->diatonicChords();
        $usableDegrees = array_column(
            array_filter($chords, static fn (array $c) => $c['quality'] !== 'dim'),
            'degree',
        );

        $selectedDegrees = [1];
        $rest = array_values(array_diff($usableDegrees, [1]));
        shuffle($rest);
        foreach (array_slice($rest, 0, 3) as $degree) {
            $selectedDegrees[] = $degree;
        }
        shuffle($selectedDegrees);

        $namesByDegree = array_column($chords, 'name', 'degree');

        return array_map(static fn (int $degree) => $namesByDegree[$degree], $selectedDegrees);
    }
}
