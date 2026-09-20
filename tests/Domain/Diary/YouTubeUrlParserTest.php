<?php

declare(strict_types=1);

namespace App\Tests\Domain\Diary;

use App\Domain\Diary\YouTubeUrlParser;
use PHPUnit\Framework\TestCase;

final class YouTubeUrlParserTest extends TestCase
{
    public function testExtractsIdFromWatchUrl(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testExtractsIdFromWatchUrlWithOtherParamsBeforeV(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ&t=42s'));
    }

    public function testExtractsIdFromShortLink(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://youtu.be/dQw4w9WgXcQ'));
    }

    public function testExtractsIdFromShortsUrl(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://youtube.com/shorts/dQw4w9WgXcQ'));
    }

    public function testExtractsIdFromEmbedUrl(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://www.youtube.com/embed/dQw4w9WgXcQ'));
    }

    public function testAcceptsMobileSubdomain(): void
    {
        self::assertSame('dQw4w9WgXcQ', YouTubeUrlParser::videoId('https://m.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testRejectsNonYouTubeUrl(): void
    {
        self::assertNull(YouTubeUrlParser::videoId('https://example.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testRejectsHttp(): void
    {
        self::assertNull(YouTubeUrlParser::videoId('http://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testRejectsYouTubeUrlWithoutAVideoId(): void
    {
        self::assertNull(YouTubeUrlParser::videoId('https://www.youtube.com/'));
        self::assertNull(YouTubeUrlParser::videoId('https://www.youtube.com/watch?list=PL123'));
    }
}
