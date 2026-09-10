<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user-named bucket for grouping exercises (e.g. "Repertoire", "Gehör") -
 * replaces the old fixed Theorie/Praxis split. No parent reference by
 * design: nesting isn't just disallowed by validation, the schema itself
 * has nowhere to put it. Capped at 6 per visitor - enforced in
 * ExerciseGroupRepository before persist(), not a DB constraint (MySQL
 * can't declaratively express "max 6 rows per visitor_id").
 */
#[ORM\Entity(repositoryClass: ExerciseGroupRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
class ExerciseGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column]
    private int $sortIndex;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $visitorId, string $name, int $sortIndex)
    {
        $this->visitorId = $visitorId;
        $this->name = $name;
        $this->sortIndex = $sortIndex;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSortIndex(): int
    {
        return $this->sortIndex;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function moveTo(int $sortIndex): void
    {
        $this->sortIndex = $sortIndex;
    }
}
