<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\PracticeSession;
use App\Repository\PracticeSessionRepository;

/**
 * A visitor's personal best score and fastest time for one tool, and
 * whether the round just finished set a new one.
 *
 * Score is ranked by (correctCount/totalQuestions, totalQuestions), both
 * maximized, compared via cross-multiplication rather than as a float ratio
 * - a perfect 30/30 beats a perfect 3/3, but a higher accuracy at fewer
 * questions still beats a lower accuracy at more.
 *
 * Speed is only compared among rounds with the SAME totalQuestions as the
 * one just finished, not a per-question average - question count is a
 * deliberate setting, not an incidental value, so "fastest round with N
 * questions" is a clearer, less arbitrary comparison than inventing a
 * normalization.
 *
 * A round is only flagged as a new record when it's strictly better than
 * every other finished round for that tool - so a visitor's very first
 * round never gets flagged (nothing to beat yet), and an exact tie never
 * re-announces an already-held record.
 */
final class PersonalRecords
{
    public function __construct(private readonly PracticeSessionRepository $sessions)
    {
    }

    /**
     * $currentCorrect/$currentTotal are passed explicitly rather than read
     * off $current->getCorrectCount()/getTotalQuestions() - those entity
     * fields are only ever updated by the async practice-sync heartbeat
     * (applySync(), fired client-side from the browser), which hasn't
     * landed yet on the very first render of a just-finished round (the
     * response to answer() itself). The session-stored round array's own
     * counters are already correct at that point - use those instead.
     *
     * @return array{bestCorrect: int, bestTotal: int, isNewScoreRecord: bool, fastestSeconds: int|null, fastestQuestionCount: int, isNewSpeedRecord: bool}
     */
    public function forTool(string $visitorId, string $tool, PracticeSession $current, int $currentCorrect, int $currentTotal): array
    {
        return self::calculate($this->sessions->finishedForTool($visitorId, $tool), $current, $currentCorrect, $currentTotal);
    }

    /**
     * @param PracticeSession[] $finishedSessions
     * @return array{bestCorrect: int, bestTotal: int, isNewScoreRecord: bool, fastestSeconds: int|null, fastestQuestionCount: int, isNewSpeedRecord: bool}
     */
    public static function calculate(array $finishedSessions, PracticeSession $current, int $currentCorrect, int $currentTotal): array
    {
        $currentSeconds = $current->getFinishedAt() !== null
            ? $current->getFinishedAt()->getTimestamp() - $current->getStartedAt()->getTimestamp()
            : null;

        // Tracked separately from the current round so a tie (or a round
        // with nothing comparable in its history, e.g. the first-ever round
        // at a given question count) never gets flagged as "new" just
        // because nothing beat it - only a round strictly better than what
        // came before earns the flag.
        $priorBestCorrect = null;
        $priorBestTotal = null;
        $priorFastestSeconds = null;

        foreach ($finishedSessions as $session) {
            if ($session === $current) {
                continue;
            }

            $correct = $session->getCorrectCount() ?? 0;
            $total = $session->getTotalQuestions() ?? 0;
            if ($total > 0 && ($priorBestCorrect === null || self::isBetterScore($correct, $total, $priorBestCorrect, $priorBestTotal))) {
                $priorBestCorrect = $correct;
                $priorBestTotal = $total;
            }

            if ($total === $currentTotal && $session->getFinishedAt() !== null) {
                $seconds = $session->getFinishedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp();
                if ($priorFastestSeconds === null || $seconds < $priorFastestSeconds) {
                    $priorFastestSeconds = $seconds;
                }
            }
        }

        $isNewScoreRecord = $priorBestCorrect !== null
            && self::isBetterScore($currentCorrect, $currentTotal, $priorBestCorrect, $priorBestTotal);
        [$bestCorrect, $bestTotal] = $priorBestCorrect === null || $isNewScoreRecord
            ? [$currentCorrect, $currentTotal]
            : [$priorBestCorrect, $priorBestTotal];

        $isNewSpeedRecord = $priorFastestSeconds !== null && $currentSeconds !== null && $currentSeconds < $priorFastestSeconds;
        $fastestSeconds = match (true) {
            $currentSeconds === null => $priorFastestSeconds,
            $priorFastestSeconds === null => $currentSeconds,
            default => min($currentSeconds, $priorFastestSeconds),
        };

        return [
            'bestCorrect' => $bestCorrect,
            'bestTotal' => $bestTotal,
            'isNewScoreRecord' => $isNewScoreRecord,
            'fastestSeconds' => $fastestSeconds,
            'fastestQuestionCount' => $currentTotal,
            'isNewSpeedRecord' => $isNewSpeedRecord,
        ];
    }

    /**
     * Lexicographic: accuracy ratio first (via cross-multiplication, no
     * floats), total question count as the tiebreak - a perfect 30/30
     * outranks a perfect 3/3 even though both are a 100% ratio.
     */
    private static function isBetterScore(int $correctA, int $totalA, int $correctB, int $totalB): bool
    {
        $ratioComparison = $correctA * $totalB <=> $correctB * $totalA;

        return $ratioComparison > 0 || ($ratioComparison === 0 && $totalA > $totalB);
    }
}
