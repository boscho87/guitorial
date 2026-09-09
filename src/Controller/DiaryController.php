<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Diary\DiaryDayViewBuilder;
use App\Domain\Diary\DiarySuggestions;
use App\Domain\Quiz\ToolRegistry;
use App\Entity\DiaryCheck;
use App\Entity\DiaryItem;
use App\Repository\DiaryCheckRepository;
use App\Repository\DiaryItemRepository;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tagebuch')]
final class DiaryController extends AbstractController
{
    private const CSRF_INTENTION = 'diary';
    private const RECENT_DAYS = 7;
    private const LABEL_MAX_LENGTH = 120;
    private const MAX_MINUTES = 120;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly DiarySuggestions $suggestions,
        private readonly DiaryDayViewBuilder $dayViewBuilder,
    ) {
    }

    #[Route('', name: 'diary_index', methods: ['GET'])]
    public function index(Request $request, DiaryItemRepository $items, DiaryCheckRepository $checks, PracticeSessionRepository $sessions): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');
        $viewDate = $this->readViewDate($request, $today);
        $isToday = $viewDate->format('Y-m-d') === $today->format('Y-m-d');

        // Fetched once, unbounded by the viewed date - buildDay()/buildRecentRatios()
        // do their own date filtering in PHP, same as HistoryStatsBuilder.
        $allVersions = $visitorId !== '' ? $items->forVisitor($visitorId) : [];
        $recentChecks = $visitorId !== '' ? $checks->forVisitorSince($visitorId, $today->modify('-'.self::RECENT_DAYS.' days')) : [];
        $finished = $visitorId !== '' ? array_values(array_filter($sessions->forVisitor($visitorId, 200), static fn ($s) => $s->isFinished())) : [];

        return $this->render('diary/index.html.twig', [
            'viewDate' => $viewDate,
            'isToday' => $isToday,
            'previousDate' => $viewDate->modify('-1 day'),
            'nextDate' => $isToday ? null : $viewDate->modify('+1 day'),
            'dayItems' => $this->dayViewBuilder->buildDay($allVersions, $recentChecks, $finished, $viewDate),
            'recentRatios' => $this->dayViewBuilder->buildRecentRatios($allVersions, $recentChecks, $finished, $today, self::RECENT_DAYS),
            'categories' => DiaryItem::CATEGORIES,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/verwalten', name: 'diary_manage', methods: ['GET'])]
    public function manage(Request $request, DiaryItemRepository $items): Response
    {
        $visitorId = $this->requireVisitorId($request);
        $today = new \DateTimeImmutable('today');
        $allVersions = $visitorId !== '' ? $items->forVisitor($visitorId) : [];
        $activeItems = array_values(array_filter($allVersions, static fn (DiaryItem $i) => $i->isActiveOn($today)));

        $toolTitles = array_column($this->tools->all(), 'title', 'id');

        return $this->render('diary/manage.html.twig', [
            'activeItems' => $activeItems,
            'suggestions' => $this->suggestions->all(),
            'tools' => $this->tools->all(),
            'toolTitles' => $toolTitles,
            'categories' => DiaryItem::CATEGORIES,
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    #[Route('/hinzufuegen', name: 'diary_add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $visitorId = $this->requireVisitorId($request);
        [$label, $category, $linkedTool, $targetMinutes] = $this->readItemInput($request);
        if ($visitorId !== '' && $label !== '') {
            $em->persist(new DiaryItem($visitorId, $label, $category, $linkedTool, $targetMinutes, new \DateTimeImmutable('today')));
            $em->flush();
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/bearbeiten', name: 'diary_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, DiaryItemRepository $items, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $item = $items->findForVisitor($id, $this->requireVisitorId($request));
        if ($item !== null) {
            [$label, $category, $linkedTool, $targetMinutes] = $this->readItemInput($request);
            if ($label !== '') {
                $items->edit($item, $label, $category, $linkedTool, $targetMinutes, new \DateTimeImmutable('today'));
                $em->flush();
            }
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/entfernen', name: 'diary_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id, Request $request, DiaryItemRepository $items, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $item = $items->findForVisitor($id, $this->requireVisitorId($request));
        if ($item !== null) {
            $items->remove($item, new \DateTimeImmutable('today'));
            $em->flush();
        }

        return $this->redirectToRoute('diary_manage');
    }

    #[Route('/{id}/abhaken', name: 'diary_toggle_check', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleCheck(int $id, Request $request, DiaryItemRepository $items, DiaryCheckRepository $checks, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $today = new \DateTimeImmutable('today');
        $item = $items->findForVisitor($id, $this->requireVisitorId($request));
        if ($item !== null && !$item->isAutoTracked() && $item->isActiveOn($today)) {
            $existing = $checks->findForToday($item, $today);
            if ($existing !== null) {
                $em->remove($existing);
            } else {
                $em->persist(new DiaryCheck($item, $today));
            }
            $em->flush();
        }

        return $this->redirectToRoute('diary_index');
    }

    private function requireVisitorId(Request $request): string
    {
        $v = $request->attributes->get('visitorId');

        return is_string($v) ? $v : '';
    }

    /**
     * Reads the ?datum=YYYY-MM-DD paging param, clamped to never exceed
     * today - future days have no checklist yet.
     */
    private function readViewDate(Request $request, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $raw = (string) $request->query->get('datum');
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            if ($parsed !== false) {
                $parsed = $parsed->setTime(0, 0);
                if ($parsed <= $today) {
                    return $parsed;
                }
            }
        }

        return $today;
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: ?int}
     */
    private function readItemInput(Request $request): array
    {
        $label = mb_substr(trim((string) $request->request->get('label')), 0, self::LABEL_MAX_LENGTH);
        $category = (string) $request->request->get('category');
        if (!in_array($category, DiaryItem::CATEGORIES, true)) {
            $category = DiaryItem::CATEGORY_PRACTICE;
        }
        $linkedToolInput = (string) $request->request->get('linked_tool');
        $linkedTool = $linkedToolInput !== '' && $this->isKnownTool($linkedToolInput) ? $linkedToolInput : null;
        $targetMinutes = null;
        if ($linkedTool !== null) {
            $targetMinutes = max(1, min(self::MAX_MINUTES, (int) $request->request->get('target_minutes', 0)));
            if ((int) $request->request->get('target_minutes', 0) <= 0) {
                $targetMinutes = null;
            }
        }

        return [$label, $category, $linkedTool, $targetMinutes];
    }

    private function isKnownTool(string $id): bool
    {
        try {
            $this->tools->get($id);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
