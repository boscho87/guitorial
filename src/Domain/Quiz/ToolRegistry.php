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
                'title' => 'key_recognition.title',
                'description' => 'key_recognition.description',
                'route' => 'key_recognition_settings',
            ],
            [
                'id' => 'kapo-trainer',
                'title' => 'capo_trainer.title',
                'description' => 'capo_trainer.description',
                'route' => 'capo_trainer_practice',
            ],
            [
                'id' => 'funktion-bestimmen',
                'title' => 'harmonic_function.title',
                'description' => 'harmonic_function.description',
                'route' => 'harmonic_function_settings',
            ],
        ];
    }

    /**
     * @return array{id: string, title: string, description: string, route: string}
     */
    public function get(string $id): array
    {
        foreach ($this->all() as $tool) {
            if ($tool['id'] === $id) {
                return $tool;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown tool "%s".', $id));
    }
}
