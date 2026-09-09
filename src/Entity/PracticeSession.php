<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PracticeSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One practice round's result, synced from the browser (on start, every
 * few seconds, and on completion) so progress survives even if the tab
 * closes mid-round. Scoped to an anonymous visitorId cookie for now - once
 * accounts exist, sessions for a visitorId get reassigned to the user that
 * logs in with it, rather than migrated/copied.
 */
#[ORM\Entity(repositoryClass: PracticeSessionRepository::class)]
#[ORM\UniqueConstraint(columns: ['visitor_id', 'client_session_id'])]
#[ORM\Index(columns: ['visitor_id'])]
class PracticeSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(length: 36)]
    private string $clientSessionId;

    #[ORM\Column(length: 40)]
    private string $tool;

    #[ORM\Column]
    private int $questionIndex = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalQuestions = null;

    #[ORM\Column(nullable: true)]
    private ?int $correctCount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $visitorId, string $clientSessionId, string $tool)
    {
        $this->visitorId = $visitorId;
        $this->clientSessionId = $clientSessionId;
        $this->tool = $tool;
        $this->startedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getClientSessionId(): string
    {
        return $this->clientSessionId;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getQuestionIndex(): int
    {
        return $this->questionIndex;
    }

    public function getTotalQuestions(): ?int
    {
        return $this->totalQuestions;
    }

    public function getCorrectCount(): ?int
    {
        return $this->correctCount;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function isFinished(): bool
    {
        return $this->finishedAt !== null;
    }

    public function applySync(int $questionIndex, ?int $totalQuestions, ?int $correctCount, bool $finished): void
    {
        $this->questionIndex = $questionIndex;
        $this->totalQuestions = $totalQuestions;
        $this->correctCount = $correctCount;
        $this->updatedAt = new \DateTimeImmutable();
        if ($finished && $this->finishedAt === null) {
            $this->finishedAt = $this->updatedAt;
        }
    }
}
