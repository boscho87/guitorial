<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\PracticeSession;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * XLSX/PDF export of a visitor's own practice stats/history - unlike
 * DataExportController (the DSGVO account dump), this is open to anonymous
 * visitors too, same as /verlauf itself (both are scoped by the visitorId
 * cookie, not a login). XLSX (multiple sheets + native Excel charts) for
 * anyone who wants to dig into the raw data, PDF for a short, printable
 * summary - the PDF deliberately stays stats-only, the full row-by-row
 * history lives in the XLSX's own sheet instead of duplicating it onto
 * paper. Both share STATS_WINDOW with HistoryController's own chart/stats,
 * so the numbers on the page and in either export always agree.
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

    #[Route('/verlauf/export.xlsx', name: 'history_export_xlsx', methods: ['GET'])]
    public function xlsx(Request $request, PracticeSessionRepository $sessions, ExerciseVersionRepository $versions): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $rows = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];
        $stats = $this->statsBuilder->build($rows);
        $exerciseTitles = $this->latestTitleByExerciseId($versions, $visitorId);

        $spreadsheet = new Spreadsheet();
        $this->buildOverviewSheet($spreadsheet->getActiveSheet(), $stats);
        $this->buildToolBreakdownSheet($spreadsheet->createSheet(), $rows);
        $this->buildExerciseBreakdownSheet($spreadsheet->createSheet(), $rows, $exerciseTitles);
        $this->buildHistorySheet($spreadsheet->createSheet(), $rows, $exerciseTitles);
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        // Charts are silently dropped from the file unless explicitly
        // told to include them - not the default, easy to miss.
        $writer->setIncludeCharts(true);
        $response = new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            sprintf('guitorial-verlauf-%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }

    #[Route('/verlauf/export.pdf', name: 'history_export_pdf', methods: ['GET'])]
    public function pdf(Request $request, PracticeSessionRepository $sessions, Environment $twig): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $rows = $visitorId !== '' ? $sessions->forVisitor($visitorId, self::STATS_WINDOW) : [];
        $stats = $this->statsBuilder->build($rows);

        // Stats only, deliberately - the full row-by-row history belongs in
        // the XLSX export's own sheet, not duplicated onto paper here.
        $html = $twig->render('history/_export_pdf.html.twig', [
            'stats' => $stats,
            'generatedAt' => new \DateTimeImmutable(),
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

    /**
     * @param array{currentStreak: int, longestStreak: int, totalPracticeDays: int, totalFinishedRounds: int, totalDurationMinutes: int, averageAccuracy: ?float, series: array<string, array{label: string, points: list<array{date: \DateTimeImmutable, accuracy: float}>}>} $stats
     */
    private function buildOverviewSheet(Worksheet $sheet, array $stats): void
    {
        $sheet->setTitle('Übersicht');
        $sheet->setCellValue('A1', 'Guitorial – Übungsstatistiken');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $labels = [
            ['Aktueller Streak (Tage)', $stats['currentStreak']],
            ['Längster Streak (Tage)', $stats['longestStreak']],
            ['Übungstage gesamt', $stats['totalPracticeDays']],
            ['Abgeschlossene Runden', $stats['totalFinishedRounds']],
            ['Übungszeit gesamt (Min.)', $stats['totalDurationMinutes']],
            ['Ø Trefferquote (%)', $stats['averageAccuracy'] !== null ? round($stats['averageAccuracy'], 1) : null],
        ];
        $row = 3;
        foreach ($labels as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(14);

        $all = $stats['series']['all'] ?? null;
        if ($all === null || count($all['points']) < 2) {
            return;
        }

        $dataStartRow = $row + 2;
        $sheet->setCellValue("A{$dataStartRow}", 'Datum');
        $sheet->setCellValue("B{$dataStartRow}", 'Trefferquote (%)');
        $sheet->getStyle("A{$dataStartRow}:B{$dataStartRow}")->getFont()->setBold(true);
        $r = $dataStartRow + 1;
        foreach ($all['points'] as $point) {
            $sheet->setCellValueExplicit("A{$r}", $point['date']->format('d.m.Y'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $point['accuracy']);
            $r++;
        }
        $lastDataRow = $r - 1;
        $pointCount = $lastDataRow - $dataStartRow;

        $categories = [new DataSeriesValues('String', "'Übersicht'!\$A\$" . ($dataStartRow + 1) . ":\$A\${$lastDataRow}", null, $pointCount)];
        $values = [new DataSeriesValues('Number', "'Übersicht'!\$B\$" . ($dataStartRow + 1) . ":\$B\${$lastDataRow}", null, $pointCount)];

        $series = new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, [0], [], $categories, $values);
        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $chart = new Chart('accuracyTrend', new Title('Trefferquote im Zeitverlauf'), $legend, $plotArea);
        $chart->setTopLeftPosition('D3');
        $chart->setBottomRightPosition('L20');
        $sheet->addChart($chart);
    }

    /**
     * @param PracticeSession[] $rows
     */
    private function buildToolBreakdownSheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Pro Werkzeug');
        $sheet->setCellValue('A1', 'Werkzeug');
        $sheet->setCellValue('B1', 'Abgeschlossene Runden');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $counts = [];
        foreach ($rows as $session) {
            if (!$session->isFinished()) {
                continue;
            }
            $toolId = $session->getTool();
            if ($toolId === null) {
                continue;
            }
            $counts[$toolId] = ($counts[$toolId] ?? 0) + 1;
        }

        $row = 2;
        foreach ($this->tools->all() as $tool) {
            $sheet->setCellValue("A{$row}", $this->translator->trans($tool['title']));
            $sheet->setCellValue("B{$row}", $counts[$tool['id']] ?? 0);
            $row++;
        }
        $lastRow = $row - 1;
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(22);

        if ($counts === []) {
            return;
        }

        $categories = [new DataSeriesValues('String', "'Pro Werkzeug'!\$A\$2:\$A\${$lastRow}", null, $lastRow - 1)];
        $values = [new DataSeriesValues('Number', "'Pro Werkzeug'!\$B\$2:\$B\${$lastRow}", null, $lastRow - 1)];

        $series = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [], $categories, $values);
        $series->setPlotDirection(DataSeries::DIRECTION_COL);
        $plotArea = new PlotArea(null, [$series]);
        $chart = new Chart('toolBreakdown', new Title('Runden pro Werkzeug'), new Legend(Legend::POSITION_RIGHT, null, false), $plotArea);
        $chart->setTopLeftPosition('D2');
        $chart->setBottomRightPosition('L18');
        $sheet->addChart($chart);
    }

    /**
     * Menge (Sitzungen/Minuten) und Qualität (Lernstand-Verteilung) pro
     * Übung - the tool breakdown sheet only ever covers the 5 built-in
     * tools, so a visitor's own diary/routine exercises (manual or
     * resource-linked, PracticeSession::getExercise() !== null) had no
     * quantity/quality view of their own at all until now.
     *
     * @param PracticeSession[] $rows
     * @param array<int, string> $exerciseTitles
     */
    private function buildExerciseBreakdownSheet(Worksheet $sheet, array $rows, array $exerciseTitles): void
    {
        $sheet->setTitle('Pro Übung');
        $headers = ['Übung', 'Sitzungen', 'Minuten gesamt', 'Zäh', 'Wird besser', 'Sicher'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $stats = [];
        foreach ($rows as $session) {
            $exerciseId = $session->getExercise()?->getId();
            if ($exerciseId === null || !$session->isFinished()) {
                continue;
            }
            $stats[$exerciseId] ??= ['sessions' => 0, 'minutes' => 0, 'schwierig' => 0, 'wird_besser' => 0, 'sicher' => 0];
            $stats[$exerciseId]['sessions']++;
            $stats[$exerciseId]['minutes'] += $this->minutes($session) ?? 0;
            $state = $session->getLearningState();
            if ($state !== null && isset($stats[$exerciseId][$state])) {
                $stats[$exerciseId][$state]++;
            }
        }

        $row = 2;
        foreach ($stats as $exerciseId => $s) {
            $sheet->setCellValue("A{$row}", $exerciseTitles[$exerciseId] ?? 'Übung');
            $sheet->setCellValue("B{$row}", $s['sessions']);
            $sheet->setCellValue("C{$row}", $s['minutes']);
            $sheet->setCellValue("D{$row}", $s['schwierig']);
            $sheet->setCellValue("E{$row}", $s['wird_besser']);
            $sheet->setCellValue("F{$row}", $s['sicher']);
            $row++;
        }
        $lastRow = $row - 1;
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if ($stats === []) {
            return;
        }

        // Stacked bar: height = how much a given exercise has actually been
        // rated (menge), colour split = how that practice has gone so far
        // (qualität) - one chart doing both axes the user asked for instead
        // of two separate ones.
        $categories = [new DataSeriesValues('String', "'Pro Übung'!\$A\$2:\$A\${$lastRow}", null, $lastRow - 1)];
        $seriesLabels = [
            new DataSeriesValues('String', "'Pro Übung'!\$D\$1", null, 1),
            new DataSeriesValues('String', "'Pro Übung'!\$E\$1", null, 1),
            new DataSeriesValues('String', "'Pro Übung'!\$F\$1", null, 1),
        ];
        $values = [
            new DataSeriesValues('Number', "'Pro Übung'!\$D\$2:\$D\${$lastRow}", null, $lastRow - 1),
            new DataSeriesValues('Number', "'Pro Übung'!\$E\$2:\$E\${$lastRow}", null, $lastRow - 1),
            new DataSeriesValues('Number', "'Pro Übung'!\$F\$2:\$F\${$lastRow}", null, $lastRow - 1),
        ];

        $series = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, range(0, 2), $seriesLabels, array_fill(0, 3, $categories[0]), $values);
        $series->setPlotDirection(DataSeries::DIRECTION_COL);
        $plotArea = new PlotArea(null, [$series]);
        $chart = new Chart('exerciseQuality', new Title('Sitzungen pro Übung nach Lernstand'), new Legend(Legend::POSITION_RIGHT, null, false), $plotArea);
        $chart->setTopLeftPosition('H2');
        $chart->setBottomRightPosition('P20');
        $sheet->addChart($chart);
    }

    /**
     * @param PracticeSession[] $rows
     * @param array<int, string> $exerciseTitles
     */
    private function buildHistorySheet(Worksheet $sheet, array $rows, array $exerciseTitles): void
    {
        $sheet->setTitle('Verlauf');
        $headers = ['Datum', 'Übung', 'Status', 'Richtig', 'Gesamt', 'Lernstand', 'Minuten', 'Notiz'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $row = 2;
        foreach ($rows as $session) {
            $values = [
                $session->getStartedAt()->format('d.m.Y H:i'),
                $this->resolveTitle($session, $exerciseTitles),
                $this->statusLabel($session),
                $session->getCorrectCount(),
                $session->getTotalQuestions(),
                $this->learningStateLabel($session),
                $this->minutes($session),
                $session->getNote(),
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $value);
            }
            $row++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function minutes(PracticeSession $session): ?int
    {
        if ($session->getFinishedAt() === null) {
            return null;
        }

        return (int) round(($session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp()) / 60);
    }

    /**
     * @return array<int, string> exercise id => its most recent version's
     *   title, regardless of whether that version (or the exercise itself)
     *   is still active today - an archived/deactivated exercise's history
     *   rows still need a name to show.
     */
    private function latestTitleByExerciseId(ExerciseVersionRepository $versions, string $visitorId): array
    {
        if ($visitorId === '') {
            return [];
        }

        $titles = [];
        foreach ($versions->forVisitor($visitorId) as $version) {
            $exerciseId = $version->getExercise()->getId();
            // forVisitor() has no guaranteed order - keep whichever version
            // validFrom's latest for this exercise.
            if (!isset($titles[$exerciseId]) || $version->getValidFrom() > $titles[$exerciseId]['validFrom']) {
                $titles[$exerciseId] = ['title' => $version->getTitle(), 'validFrom' => $version->getValidFrom()];
            }
        }

        return array_map(static fn (array $t) => $t['title'], $titles);
    }

    /**
     * Checks the linked Exercise's own title first - an export is exactly
     * the place someone wants to know *which* exercise a row was, not just
     * which of the 5 built-in tools it happened to use.
     *
     * @param array<int, string> $exerciseTitles
     */
    private function resolveTitle(PracticeSession $session, array $exerciseTitles): string
    {
        $exerciseId = $session->getExercise()?->getId();
        if ($exerciseId !== null && isset($exerciseTitles[$exerciseId])) {
            return $exerciseTitles[$exerciseId];
        }

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
