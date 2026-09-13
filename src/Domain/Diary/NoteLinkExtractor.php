<?php

declare(strict_types=1);

namespace App\Domain\Diary;

/**
 * Pulls https:// links out of an exercise note's free text, so pasting a
 * reference link (e.g. a YouTube tutorial) into the note is enough on its
 * own - no need to separately fill in the Ressourcen section for the same
 * URL. Only https:// is recognised, matching the same scheme requirement
 * DiaryController::readResourceInput() already enforces for manually
 * entered external-link resources.
 */
final class NoteLinkExtractor
{
    /**
     * @return list<string> distinct URLs, in the order they first appear
     */
    public static function extract(?string $note): array
    {
        if ($note === null || $note === '') {
            return [];
        }

        preg_match_all('/https:\/\/\S+/', $note, $matches);

        $urls = [];
        foreach ($matches[0] as $match) {
            // Strip trailing punctuation a sentence would naturally have
            // right after a pasted link (a period, a closing parenthesis…).
            $url = rtrim($match, ".,;:!?)]}'\"");
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
