<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks up a YouTube video's title via the public oEmbed endpoint (no API
 * key needed) - shown instead of the raw URL on a resource button, the same
 * way a browser bookmark shows a page's <title> on hover rather than its
 * URL. Best-effort only: any failure (network, timeout, private/deleted
 * video, malformed response) just means no title, never an error the
 * visitor sees - saving a resource must never depend on YouTube being
 * reachable.
 */
final class YouTubeTitleFetcher
{
    private const TIMEOUT_SECONDS = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(string $videoId): ?string
    {
        try {
            $response = $this->httpClient->request('GET', 'https://www.youtube.com/oembed', [
                'query' => ['url' => 'https://www.youtube.com/watch?v=' . $videoId, 'format' => 'json'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = $response->toArray();
        } catch (ExceptionInterface | \JsonException) {
            return null;
        }

        return is_string($data['title'] ?? null) && $data['title'] !== '' ? $data['title'] : null;
    }
}
