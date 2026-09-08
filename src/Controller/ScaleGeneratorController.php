<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Theory\Scale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A random scale/key generator for improvisation practice - no right/wrong
 * answer, just "here's what to play over next". Draws without repeats from
 * a shuffled pool of the active scale types until it's exhausted, then
 * reshuffles (a new "round"), mirroring therandomscalemachine.com's
 * behaviour but scoped to a single, simpler tool.
 */
#[Route('/skalen-generator')]
final class ScaleGeneratorController extends AbstractController
{
    private const SESSION_ROUND = 'scale_generator.round';
    private const CSRF_INTENTION = 'scale-generator';

    #[Route('', name: 'scale_generator_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        $round = $this->currentRound($request);

        return $this->render('scale_generator/settings.html.twig', [
            'typeIds' => Scale::typeIds(),
            'activeTypes' => $round !== null ? $round['types'] : Scale::typeIds(),
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/start', name: 'scale_generator_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $types = array_values(array_intersect($request->request->all('types'), Scale::typeIds()));
        if ($types === []) {
            $types = Scale::typeIds();
        }

        $this->beginRound($request, $types);

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
            $round['queue'] = $this->shuffledPool($round['types']);
            $round['roundNumber']++;
            $round['drawnCount'] = 0;
        }

        $round['current'] = array_shift($round['queue']);
        $round['drawnCount']++;
        $session->set(self::SESSION_ROUND, $round);

        return $this->render('scale_generator/practice.html.twig', $this->viewData($request));
    }

    #[Route('/neu-mischen', name: 'scale_generator_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->assertValidCsrf($request);

        $round = $this->currentRound($request);
        $types = $round !== null ? $round['types'] : Scale::typeIds();

        $this->beginRound($request, $types);

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
     * @return array{types: string[], queue: string[], current: string|null, drawnCount: int, poolSize: int, roundNumber: int, startedAt: float}|null
     */
    private function currentRound(Request $request): ?array
    {
        $session = $request->getSession();
        $round = $session->get(self::SESSION_ROUND);

        if (
            !is_array($round)
            || !isset($round['types'], $round['queue'], $round['drawnCount'], $round['poolSize'], $round['roundNumber'], $round['startedAt'])
            || !array_key_exists('current', $round)
        ) {
            $session->remove(self::SESSION_ROUND);

            return null;
        }

        return $round;
    }

    /**
     * @param string[] $types
     */
    private function beginRound(Request $request, array $types): void
    {
        $queue = $this->shuffledPool($types);

        $request->getSession()->set(self::SESSION_ROUND, [
            'types' => $types,
            'queue' => $queue,
            'current' => array_shift($queue),
            'drawnCount' => 1,
            'poolSize' => count($queue) + 1,
            'roundNumber' => 1,
            'startedAt' => microtime(true),
        ]);
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    private function shuffledPool(array $types): array
    {
        $ids = array_keys(array_filter(
            Scale::pool(),
            static fn (Scale $scale): bool => in_array($scale->typeId, $types, true),
        ));
        shuffle($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function viewData(Request $request): array
    {
        $round = $this->currentRound($request);
        $scale = Scale::get($round['current']);

        return [
            'typeIds' => Scale::typeIds(),
            'activeTypes' => $round['types'],
            'tonic' => $scale->tonic,
            'typeLabel' => Scale::typeLabel($scale->typeId),
            'label' => $scale->label(),
            'notes' => $scale->notes,
            'drawnCount' => $round['drawnCount'],
            'poolSize' => $round['poolSize'],
            'roundNumber' => $round['roundNumber'],
            'startedAtMs' => (int) round($round['startedAt'] * 1000),
            'csrfIntention' => self::CSRF_INTENTION,
        ];
    }
}
