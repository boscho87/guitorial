<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Domain\Quiz\ToolRegistry;
use App\Entity\DiaryCheck;
use App\Entity\DiaryItem;
use App\Entity\PracticeSession;

/**
 * Resolves a visitor's DiaryItem versions + checks + practice history into
 * what a given calendar day's checklist actually looked like. Auto-tracked
 * items are never read from storage - their checked state is always
 * recomputed from that day's practiced minutes, the same "recompute, don't
 * trust a stored flag" principle the rest of the app already uses for
 * grading.
 */
final class DiaryDayViewBuilder
{
    private const WEEKDAY_LABELS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * @param DiaryItem[] $allVersions
     * @param DiaryCheck[] $checks checks that may cover $date
     * @param PracticeSession[] $finishedSessions finished sessions
     * @return list<array{item: DiaryItem, checked: bool, autoTracked: bool, practicedMinutes: ?int, toolTitle: ?string, toolRoute: ?string}>
     */
    public function buildDay(array $allVersions, array $checks, array $finishedSessions, \DateTimeImmutable $date): array
    {
        $minutesByTool = $this->minutesByToolForDay($finishedSessions, $date);
        $rows = [];
        foreach ($allVersions as $item) {
            if (!$item->isActiveOn($date)) {
                continue;
            }

            $autoTracked = $item->isAutoTracked();
            $practicedMinutes = $autoTracked ? (int) ($minutesByTool[$item->getLinkedTool()] ?? 0) : null;
            $checked = $autoTracked
                ? $practicedMinutes >= $item->getTargetMinutes()
                : $this->hasCheckFor($checks, $item, $date);

            $tool = $item->getLinkedTool() !== null ? $this->toolOrNull($item->getLinkedTool()) : null;

            $rows[] = [
                'item' => $item,
                'checked' => $checked,
                'autoTracked' => $autoTracked,
                'practicedMinutes' => $practicedMinutes,
                'toolTitle' => $tool['title'] ?? null,
                'toolRoute' => $tool['route'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Trailing window as a per-day completion RATIO, not a full item-by-item
     * matrix - different days can have different active items (edits,
     * additions, removals), so aligning them into a matrix would misrepresent
     * what changed.
     *
     * @param DiaryItem[] $allVersions
     * @param DiaryCheck[] $checks
     * @param PracticeSession[] $finishedSessions
     * @return list<array{date: \DateTimeImmutable, weekdayLabel: string, done: int, total: int}>
     */
    public function buildRecentRatios(array $allVersions, array $checks, array $finishedSessions, \DateTimeImmutable $today, int $days = 7): array
    {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days");
            $rows = $this->buildDay($allVersions, $checks, $finishedSessions, $date);
            $result[] = [
                'date' => $date,
                'weekdayLabel' => self::WEEKDAY_LABELS[(int) $date->format('N')],
                'done' => count(array_filter($rows, static fn (array $r): bool => $r['checked'])),
                'total' => count($rows),
            ];
        }

        return $result;
    }

    /**
     * @param PracticeSession[] $finishedSessions
     * @return array<string, float> minutes per tool on this day
     */
    private function minutesByToolForDay(array $finishedSessions, \DateTimeImmutable $date): array
    {
        $minutes = [];
        foreach ($finishedSessions as $s) {
            if ($s->getFinishedAt()->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            $tool = $s->getTool();
            $minutes[$tool] = ($minutes[$tool] ?? 0) + ($s->getFinishedAt()->getTimestamp() - $s->getStartedAt()->getTimestamp()) / 60;
        }

        return $minutes;
    }

    /**
     * @param DiaryCheck[] $checks
     */
    private function hasCheckFor(array $checks, DiaryItem $item, \DateTimeImmutable $date): bool
    {
        foreach ($checks as $check) {
            if ($check->getItem() === $item && $check->getCheckedDate()->format('Y-m-d') === $date->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, title: string, description: string, route: string}|null
     */
    private function toolOrNull(string $id): ?array
    {
        try {
            return $this->tools->get($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
