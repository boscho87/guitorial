<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\DiaryItem;

/**
 * Curated starter checklist items shown as quick-fill chips on the diary
 * page, mirroring ToolRegistry's shape (static, no state, labels are
 * translation keys). One theory suggestion per existing tool (each already
 * a quiz-style theory drill, so all default to the "theorie" category) plus
 * a handful of tool-less "praxis" suggestions for real playing, which have
 * no measurable duration source and so are never auto-tracked.
 */
final class DiarySuggestions
{
    /**
     * @return list<array{label: string, category: string, linkedTool: ?string, targetMinutes: ?int}>
     */
    public function all(): array
    {
        return [
            ['label' => 'diary.suggestions.key_recognition', 'category' => DiaryItem::CATEGORY_THEORY, 'linkedTool' => 'tonart-erkennen', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.capo_trainer', 'category' => DiaryItem::CATEGORY_THEORY, 'linkedTool' => 'kapo-trainer', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.harmonic_function', 'category' => DiaryItem::CATEGORY_THEORY, 'linkedTool' => 'funktion-bestimmen', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.fretboard', 'category' => DiaryItem::CATEGORY_THEORY, 'linkedTool' => 'griffbrett', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.scale_generator', 'category' => DiaryItem::CATEGORY_THEORY, 'linkedTool' => 'skalen-generator', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.warm_up', 'category' => DiaryItem::CATEGORY_PRACTICE, 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.new_chords', 'category' => DiaryItem::CATEGORY_PRACTICE, 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.song_practice', 'category' => DiaryItem::CATEGORY_PRACTICE, 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.repertoire', 'category' => DiaryItem::CATEGORY_PRACTICE, 'linkedTool' => null, 'targetMinutes' => null],
        ];
    }
}
