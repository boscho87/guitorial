<?php

declare(strict_types=1);

namespace App\Domain\Diary;

/**
 * Pulls a YouTube video id out of the common URL shapes (watch?v=,
 * youtu.be/, shorts/, embed/) - lets the exercise-running page offer an
 * in-page player as an alternative to opening the link in a new tab.
 * Watching in a separate tab backgrounds the exercise-session tab, which
 * pauses its timer/credited time (see timer_controller.js's
 * pauseWhenHidden) - an embedded player avoids that entirely, since the
 * tab never actually loses visibility.
 */
final class YouTubeUrlParser
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    public static function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');

            return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
        }

        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }

        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;

            return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
        }

        return preg_match('#^/(?:shorts|embed)/([A-Za-z0-9_-]{11})$#', $path, $matches) === 1 ? $matches[1] : null;
    }
}
