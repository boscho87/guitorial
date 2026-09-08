<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\WeightedQuestionPicker;
use App\Domain\Theory\CapoShape;
use App\Domain\Theory\CapoTransposer;
use App\Domain\Theory\Note;
use App\Repository\QuizAttemptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kapo-trainer')]
final class CapoTrainerController extends AbstractController
{
    private const TOOL = 'kapo-trainer';
    private const SESSION_SHAPES = 'capo_trainer.active_shapes';
    private const SESSION_MAX_FRET = 'capo_trainer.max_fret';
    private const SESSION_MODE = 'capo_trainer.mode';
    private const SESSION_CURRENT = 'capo_trainer.current';
    private const CSRF_INTENTION = 'capo_trainer';
    private const DEFAULT_MAX_FRET = 7;
    private const MODES = ['1', '2', '3'];

    public function __construct(private readonly QuizAttemptRepository $attempts)
    {
    }

    #[Route('', name: 'capo_trainer_practice', methods: ['GET'])]
    public function practice(Request $request): Response
    {
        [$shapes, $maxFret, $mode] = $this->settingsFromSession($request);
        $this->ensureCurrentQuestion($request, $shapes, $maxFret, $mode);

        return $this->render('capo_trainer/practice.html.twig', $this->viewData($request, $shapes, $maxFret, $mode));
    }

    #[Route('/einstellungen', name: 'capo_trainer_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $shapes = array_values(array_intersect(
            $request->request->all('shapes'),
            array_keys(CapoShape::all()),
        ));
        if ($shapes === []) {
            $shapes = array_keys(CapoShape::all());
        }

        $maxFret = max(1, min(11, (int) $request->request->get('max_fret', self::DEFAULT_MAX_FRET)));
        $mode = $request->request->get('mode', '1');
        if (!in_array($mode, self::MODES, true)) {
            $mode = '1';
        }

        $session = $request->getSession();
        $session->set(self::SESSION_SHAPES, $shapes);
        $session->set(self::SESSION_MAX_FRET, $maxFret);
        $session->set(self::SESSION_MODE, $mode);
        $session->remove(self::SESSION_CURRENT);

        $this->ensureCurrentQuestion($request, $shapes, $maxFret, $mode);

        return $this->render('capo_trainer/practice.html.twig', $this->viewData($request, $shapes, $maxFret, $mode));
    }

    /**
     * Grades the answer and immediately generates the next question in the
     * same response - the result is shown as a toast alongside the new
     * question rather than as a separate click-through step.
     */
    #[Route('/antwort', name: 'capo_trainer_answer', methods: ['POST'])]
    public function answer(Request $request): Response
    {
        $this->assertValidCsrf($request);

        [$shapes, $maxFret, $mode] = $this->settingsFromSession($request);
        $session = $request->getSession();
        $current = $session->get(self::SESSION_CURRENT);

        $guess = (string) $request->request->get('guess');
        $expected = is_array($current) ? (string) ($current['expected'] ?? '') : '';
        $isCorrect = $expected !== '' && $guess === $expected;

        $toast = null;
        if ($expected !== '') {
            $questionKey = $mode . ':' . $current['shapeId'];
            $this->attempts->record(self::TOOL, $questionKey, $isCorrect);
            $toast = [
                'correct' => $isCorrect,
                'guess' => $guess,
                'expected' => $expected,
                'shapeId' => $current['shapeId'],
                'fret' => $current['fret'],
                'sounding' => $current['sounding'],
            ];
        }

        $session->remove(self::SESSION_CURRENT);
        $this->ensureCurrentQuestion($request, $shapes, $maxFret, $mode);

        return $this->render('capo_trainer/practice.html.twig', $this->viewData($request, $shapes, $maxFret, $mode, $toast));
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @return array{0: string[], 1: int, 2: string} */
    private function settingsFromSession(Request $request): array
    {
        $session = $request->getSession();

        $shapes = $session->get(self::SESSION_SHAPES);
        if (!is_array($shapes) || $shapes === []) {
            $shapes = array_keys(CapoShape::all());
            $session->set(self::SESSION_SHAPES, $shapes);
        }

        $maxFret = $session->get(self::SESSION_MAX_FRET);
        if (!is_int($maxFret)) {
            $maxFret = self::DEFAULT_MAX_FRET;
            $session->set(self::SESSION_MAX_FRET, $maxFret);
        }

        $mode = $session->get(self::SESSION_MODE);
        if (!in_array($mode, self::MODES, true)) {
            $mode = '1';
            $session->set(self::SESSION_MODE, $mode);
        }

        return [$shapes, $maxFret, $mode];
    }

    /** @param string[] $shapes */
    private function ensureCurrentQuestion(Request $request, array $shapes, int $maxFret, string $mode): void
    {
        $session = $request->getSession();
        $current = $session->get(self::SESSION_CURRENT);
        if (is_array($current) && ($current['mode'] ?? null) === $mode && in_array($current['shapeId'] ?? null, $shapes, true)) {
            return;
        }

        $allWrongCounts = $this->attempts->wrongCountsByQuestionKey(self::TOOL);
        $wrongCountsForMode = [];
        foreach ($allWrongCounts as $key => $count) {
            if (str_starts_with($key, $mode . ':')) {
                $wrongCountsForMode[substr($key, strlen($mode) + 1)] = $count;
            }
        }

        $shapeId = WeightedQuestionPicker::pick($shapes, $wrongCountsForMode);
        $shape = CapoShape::get($shapeId);
        $fret = random_int(1, $maxFret);
        $sounding = CapoTransposer::soundingChord($shape, $fret);

        $expected = match ($mode) {
            '1' => $sounding,
            '2' => $shapeId,
            '3' => (string) $fret,
        };

        $session->set(self::SESSION_CURRENT, [
            'mode' => $mode,
            'shapeId' => $shapeId,
            'fret' => $fret,
            'sounding' => $sounding,
            'expected' => $expected,
        ]);
    }

    /**
     * @param string[] $shapes
     * @param array{correct: bool, guess: string, expected: string, shapeId: string, fret: int, sounding: string}|null $toast result of the answer that led
     *        to this render, shown as a transient toast - null on a plain page load
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $shapes, int $maxFret, string $mode, ?array $toast = null): array
    {
        $current = $request->getSession()->get(self::SESSION_CURRENT);
        $shape = CapoShape::get($current['shapeId']);

        $answerOptions = match ($mode) {
            '1' => array_map(
                static fn (int $i) => Note::nameFromIndex($i) . ($shape->isMinor ? 'm' : ''),
                range(0, 11),
            ),
            '2' => $shapes,
            '3' => range(1, $maxFret),
        };

        return [
            'allShapes' => CapoShape::all(),
            'activeShapes' => $shapes,
            'maxFret' => $maxFret,
            'mode' => $mode,
            'shapeId' => $current['shapeId'],
            'fret' => $current['fret'],
            'sounding' => $current['sounding'],
            'answerOptions' => $answerOptions,
            'toast' => $toast,
            'summary' => $this->attempts->summary(self::TOOL),
            'csrfIntention' => self::CSRF_INTENTION,
        ];
    }
}
