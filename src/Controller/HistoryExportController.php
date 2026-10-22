<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\PracticeSession;
use App\Repository\PracticeSessionRepository;
use Dompdf\Dompdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * CSV/PDF export of a visitor's own practice stats/history - unlike
 * DataExportController (the DSGVO account dump), this is open to anonymous
 * visitors too, same as /verlauf itself (both are scoped by the visitorId
 * cookie, not a login). CSV for raw/sortable data, PDF for a readable,
 * printable/shareable summary - same STATS_WINDOW as HistoryController's
 * own chart/stats, so the numbers on the page and in the export always
 * agree.
 */
final class HistoryExportController extends AbstractController
{
    private const STATS_WINDOW = 200;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly HistoryStatsBuilder $statsBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/verlauf/export.csv', name: 'history_export_csv', methods: ['GET'])]
    public function csv(Request $request, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $rows = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];

        $response = new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM - Excel on Windows otherwise guesses the wrong
            // encoding for German umlauts in exercise titles/notes.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Datum', 'Übung', 'Status', 'Richtig', 'Gesamt', 'Lernstand', 'Minuten', 'Notiz'], ';');
            foreach ($rows as $session) {
                fputcsv($out, [
                    $session->getStartedAt()->format('Y-m-d H:i'),
                    $this->resolveTitle($session),
                    $this->statusLabel($session),
                    $session->getCorrectCount(),
                    $session->getTotalQuestions(),
                    $this->learningStateLabel($session),
                    $this->minutes($session),
                    $session->getNote(),
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            sprintf('guitorial-verlauf-%s.csv', (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }

    #[Route('/verlauf/export.pdf', name: 'history_export_pdf', methods: ['GET'])]
    public function pdf(Request $request, PracticeSessionRepository $sessions, Environment $twig): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $rows = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];
        $stats = $this->statsBuilder->build($rows);

        $html = $twig->render('history/_export_pdf.html.twig', [
            'stats' => $stats,
            'generatedAt' => new \DateTimeImmutable(),
            'rows' => array_map(fn (PracticeSession $s) => [
                'date' => $s->getStartedAt(),
                'title' => $this->resolveTitle($s),
                'status' => $this->statusLabel($s),
                'correctCount' => $s->getCorrectCount(),
                'totalQuestions' => $s->getTotalQuestions(),
                'learningState' => $this->learningStateLabel($s),
                'minutes' => $this->minutes($s),
            ], $rows),
        ]);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="guitorial-verlauf-%s.pdf"', (new \DateTimeImmutable())->format('Y-m-d')),
        ]);
    }

    private function minutes(PracticeSession $session): ?int
    {
        if ($session->getFinishedAt() === null) {
            return null;
        }

        return (int) round(($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60);
    }

    /**
     * Same recipe as HistoryController::resolveTitle() - kept as its own
     * copy rather than a shared service, matching this codebase's existing
     * per-controller small-helper convention (see requireVisitorId below).
     */
    private function resolveTitle(PracticeSession $session): string
    {
        $toolId = $session->getTool() ?? $session->getResource()?->getInternalToolId();
        if ($toolId !== null) {
            try {
                return $this->translator->trans($this->tools->get($toolId)['title']);
            } catch (\InvalidArgumentException) {
                return $toolId;
            }
        }

        return $session->getResource()?->getExternalTitle() ?? 'Übung';
    }

    private function statusLabel(PracticeSession $session): string
    {
        return match ($session->getStatus()) {
            'abgeschlossen' => $this->translator->trans('history.finished'),
            'verworfen' => $this->translator->trans('history.discarded'),
            default => $this->translator->trans('history.in_progress', ['%current%' => $session->getQuestionIndex()]),
        };
    }

    private function learningStateLabel(PracticeSession $session): ?string
    {
        $state = $session->getLearningState();

        return $state !== null ? $this->translator->trans('exercise_session.learning_state.' . $state) : null;
    }

    private function requireVisitorId(Request $request): string
    {
        $v = $request->attributes->get('visitorId');

        return is_string($v) ? $v : '';
    }
}
