<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The stable anchor for one exercise - holds exactly the fields the spec
 * says must NOT be versioned (the note) and the fields that must survive a
 * version fork (resources, via ExerciseResource pointing at the anchor, not
 * the version). Title/group/target-time/rhythm live on ExerciseVersion
 * instead, which forks the same way DiaryItem used to (see
 * ExerciseVersionRepository::edit()) - splitting anchor from version is
 * what lets a fork keep the note and resources without duplicating or
 * losing them.
 *
 * Archived (removed from the plan but history kept) is modeled as
 * `archivedAt` rather than a separate status column, so "is this exercise
 * part of the plan on day D" stays a single half-open interval check
 * (`archivedAt === null || D < archivedAt`), the same shape as
 * DiaryItem::isActiveOn() - no redundant derived state to keep in sync.
 */
#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
class Exercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $visitorId)
    {
        $this->visitorId = $visitorId;
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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        return $this->archivedAt === null || $date < $this->archivedAt;
    }

    /**
     * The note belongs to the anchor, never the version - it's always
     * editable and never forked, exactly as specified ("keine eigene
     * Versionshistorie").
     */
    public function applyNote(?string $note): void
    {
        $this->note = $note;
    }

    public function archive(\DateTimeImmutable $today): void
    {
        $this->archivedAt = $today;
    }

    public function reactivate(): void
    {
        $this->archivedAt = null;
    }
}
