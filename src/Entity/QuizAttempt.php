<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizAttemptRepository::class)]
#[ORM\Index(columns: ['tool', 'question_key'])]
#[ORM\Index(columns: ['tool', 'visitor_id'])]
class QuizAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $tool;

    #[ORM\Column(length: 80)]
    private string $questionKey;

    #[ORM\Column]
    private bool $correct;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tool, string $questionKey, bool $correct, string $visitorId)
    {
        $this->tool = $tool;
        $this->questionKey = $questionKey;
        $this->correct = $correct;
        $this->visitorId = $visitorId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getQuestionKey(): string
    {
        return $this->questionKey;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
