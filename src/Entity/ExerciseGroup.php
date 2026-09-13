<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user-named bucket for grouping exercises (e.g. "Repertoire", "Gehör").
 * No parent reference by design: nesting isn't just disallowed by
 * validation, the schema itself has nowhere to put it. Custom groups are
 * capped at 6 per visitor - enforced in ExerciseGroupRepository before
 * persist(), not a DB constraint (MySQL can't declaratively express "max 6
 * rows per visitor_id").
 *
 * Every visitor also gets two SYSTEM groups, "Theorie" and "Praxis",
 * identified by `kind` (not by name, which stays renamable) - see
 * ExerciseGroupProvisioner. They exist so "keine Gruppe" is not a state an
 * exercise can be in at all, and don't count against the custom-group cap.
 */
#[ORM\Entity(repositoryClass: ExerciseGroupRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
class ExerciseGroup
{
    public const KIND_THEORY = 'theorie';
    public const KIND_PRACTICE = 'praxis';

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

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $kind;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $visitorId, string $name, int $sortIndex, ?string $kind = null)
    {
        $this->visitorId = $visitorId;
        $this->name = $name;
        $this->sortIndex = $sortIndex;
        $this->kind = $kind;
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

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function isSystem(): bool
    {
        return $this->kind !== null;
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
