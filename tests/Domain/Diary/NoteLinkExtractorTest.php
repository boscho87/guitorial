<?php

declare(strict_types=1);

namespace App\Tests\Domain\Diary;

use App\Domain\Diary\NoteLinkExtractor;
use PHPUnit\Framework\TestCase;

final class NoteLinkExtractorTest extends TestCase
{
    public function testReturnsEmptyArrayForNullOrEmptyNote(): void
    {
        self::assertSame([], NoteLinkExtractor::extract(null));
        self::assertSame([], NoteLinkExtractor::extract(''));
    }

    public function testReturnsEmptyArrayForNoteWithoutALink(): void
    {
        self::assertSame([], NoteLinkExtractor::extract('Tonart Em, Akkordwechsel etwas tricky.'));
    }

    public function testExtractsASingleLinkFromSurroundingText(): void
    {
        $note = 'Video dazu: https://youtu.be/abc123 - gut zum Nachschauen.';

        self::assertSame(['https://youtu.be/abc123'], NoteLinkExtractor::extract($note));
    }

    public function testStripsTrailingSentencePunctuation(): void
    {
        self::assertSame(['https://example.com/x'], NoteLinkExtractor::extract('Siehe https://example.com/x.'));
        self::assertSame(['https://example.com/x'], NoteLinkExtractor::extract('(https://example.com/x)'));
    }

    public function testIgnoresNonHttpsLinks(): void
    {
        self::assertSame([], NoteLinkExtractor::extract('http://example.com/x'));
    }

    public function testExtractsMultipleDistinctLinksInOrder(): void
    {
        $note = "Erster Link https://a.example/1\nZweiter Link https://b.example/2";

        self::assertSame(['https://a.example/1', 'https://b.example/2'], NoteLinkExtractor::extract($note));
    }

    public function testDeduplicatesTheSameLinkMentionedTwice(): void
    {
        $note = 'https://a.example/1 nochmal: https://a.example/1';

        self::assertSame(['https://a.example/1'], NoteLinkExtractor::extract($note));
    }
}
