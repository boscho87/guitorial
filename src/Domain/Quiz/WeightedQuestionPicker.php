<?php

declare(strict_types=1);

namespace App\Domain\Quiz;

/**
 * Picks the next practice item, favouring ones that have been answered
 * wrong more often - pure logic, the wrong-count lookup lives in the caller.
 */
final class WeightedQuestionPicker
{
    /**
     * @param string[] $candidates non-empty list of candidate keys
     * @param array<string, int> $wrongCounts candidate => number of past wrong attempts
     */
    public static function pick(array $candidates, array $wrongCounts): string
    {
        if ($candidates === []) {
            throw new \InvalidArgumentException('No candidates to pick from.');
        }

        $weights = [];
        $total = 0;
        foreach ($candidates as $candidate) {
            $weight = 1 + max(0, $wrongCounts[$candidate] ?? 0);
            $weights[$candidate] = $weight;
            $total += $weight;
        }

        $roll = random_int(1, $total);
        foreach ($weights as $candidate => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $candidate;
            }
        }

        return end($candidates);
    }
}
