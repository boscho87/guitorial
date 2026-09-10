<?php

declare(strict_types=1);

namespace App\Domain\Diary;

/**
 * Curated starter exercises shown as quick-fill chips on the manage page,
 * mirroring ToolRegistry's shape (static, no state, labels are translation
 * keys). One suggestion per existing tool plus a handful of tool-less
 * suggestions for real playing. No fixed category any more - groups are
 * free-form and visitor-chosen, so a suggestion only prefills title/tool/
 * target minutes, never a group.
 */
final class DiarySuggestions
{
    /**
     * @return list<array{label: string, linkedTool: ?string, targetMinutes: ?int}>
     */
    public function all(): array
    {
        return [
            ['label' => 'diary.suggestions.key_recognition', 'linkedTool' => 'tonart-erkennen', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.capo_trainer', 'linkedTool' => 'kapo-trainer', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.harmonic_function', 'linkedTool' => 'funktion-bestimmen', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.fretboard', 'linkedTool' => 'griffbrett', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.scale_generator', 'linkedTool' => 'skalen-generator', 'targetMinutes' => 10],
            ['label' => 'diary.suggestions.warm_up', 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.new_chords', 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.song_practice', 'linkedTool' => null, 'targetMinutes' => null],
            ['label' => 'diary.suggestions.repertoire', 'linkedTool' => null, 'targetMinutes' => null],
        ];
    }
}
