<?php

declare(strict_types=1);

namespace App\Domain\Quiz;

/**
 * The tools shown on the hub page. A new tool is a new entry here plus its
 * own controller/templates - the hub itself never needs to change.
 */
final class ToolRegistry
{
    /**
     * @return list<array{id: string, title: string, description: string, route: string}>
     */
    public function all(): array
    {
        return [
            [
                'id' => 'tonart-erkennen',
                'title' => 'Tonart aus Akkorden erkennen',
                'description' => 'Eine Akkordfolge lesen und die passende Tonart bestimmen.',
                'route' => 'key_recognition_practice',
            ],
            [
                'id' => 'kapo-trainer',
                'title' => 'Kapo-Trainer',
                'description' => 'Griffbild, Kapo-Bund und klingenden Akkord ineinander umrechnen.',
                'route' => 'capo_trainer_practice',
            ],
        ];
    }
}
