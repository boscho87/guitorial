<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Theory\Note;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A random tone generator for practice - no right/wrong answer, just "here's
 * the next tone". For now (v1) it draws single tones rather than full
 * scales; Scale/ScaleType (Dur/Moll/Harmonisch/Melodisch) is built and
 * tested but intentionally not wired in yet, per explicit request to keep
 * this simple first and layer scale types on later.
 *
 * A round is bounded (one draw per active tone, no repeats) rather than
 * open-ended, matching the other tools' settings -> practice -> summary
 * flow. Optional auto-advance moves on after N seconds without a click.
 */
#[Route('/skalen-generator')]
final class ScaleGeneratorController extends AbstractController
{
    private const SESSION_ROUND = 'scale_generator.round';
    private const CSRF_INTENTION = 'scale-generator';
    private const MAX_AUTO_ADVANCE_SECONDS = 120;

    /** @return string[] */
    private static function allTones(): array
    {
        return array_map(static fn (int $i): string => Note::nameFromIndex($i), range(0, 11));
    }

    #[Route('', name: 'scale_generator_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);

        return $this->render('scale_generator/settings.html.twig', [
            'allTones' => self::allTones(),
            'activeTones' => $round !== null ? $round['tones'] : self::allTones(),
            'autoAdvanceSeconds' => $round !== null ? $round['autoAdvanceSeconds'] : 0,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'scale_generator_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $tones = array_values(array_intersect($request->request->all('tones'), self::allTones()));
        if ($tones === []) {
            $tones = self::allTones();
        }

        $autoAdvanceSeconds = max(0, min(
            self::MAX_AUTO_ADVANCE_SECONDS,
            (int) $request->request->get('auto_advance_seconds', 0),
        ));

        $this->beginRound($request, $tones, $autoAdvanceSeconds);

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

        if ($round['queue'] === []) {
            $round['finished'] = true;
            $round['current'] = null;
        } else {
            $round['current'] = array_shift($round['queue']);
            $round['questionIndex']++;
        }
        $session->set(self::SESSION_ROUND, $round);

        return $this->render('scale_generator/practice.html.twig', $this->viewData($request));
    }

    #[Route('/neue-runde', name: 'scale_generator_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $tones = $round !== null ? $round['tones'] : self::allTones();
        $autoAdvanceSeconds = $round !== null ? $round['autoAdvanceSeconds'] : 0;

        $this->beginRound($request, $tones, $autoAdvanceSeconds);

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
     * @return array{tones: string[], queue: string[], current: string|null, questionIndex: int, totalQuestions: int, finished: bool, autoAdvanceSeconds: int, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['tones'], $round['queue'], $round['questionIndex'], $round['totalQuestions'], $round['finished'], $round['autoAdvanceSeconds'], $round['startedAt'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $tones
     */
    private function beginRound(Request $request, array $tones, int $autoAdvanceSeconds): void
    {
        $queue = $tones;
        shuffle($queue);
        // Shift before building the array literal - 'queue' => $queue would
        // otherwise capture the pre-shift snapshot (PHP evaluates array
        // literal values left-to-right), duplicating the first tone.
        $current = array_shift($queue);

        $request->getSession()->set(self::SESSION_ROUND, [
            'tones' => $tones,
            'queue' => $queue,
            'current' => $current,
            'questionIndex' => 1,
            'totalQuestions' => count($tones),
            'finished' => false,
            'autoAdvanceSeconds' => $autoAdvanceSeconds,
            'startedAt' => microtime(true),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request): array
    {
        $round = $this->currentRound($request);

        return [
            'allTones' => self::allTones(),
            'activeTones' => $round['tones'],
            'tone' => $round['current'],
            'questionIndex' => $round['questionIndex'],
            'totalQuestions' => $round['totalQuestions'],
            'finished' => $round['finished'],
            'autoAdvanceSeconds' => $round['autoAdvanceSeconds'],
            'startedAtMs' => (int) round($round['startedAt'] * 1000),
            'elapsedLabel' => $this->formatElapsed(microtime(true) - $round['startedAt']),
            'csrfIntention' => self::CSRF_INTENTION,
        ];
    }

    private function formatElapsed(float $seconds): string
    {
        $whole = max(0, (int) round($seconds));

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
