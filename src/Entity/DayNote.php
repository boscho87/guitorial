<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DayNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A short free-text note for a whole calendar day ("heute war gut/okay/
 * schlecht" etc.) - deliberately separate from both Exercise.note (a
 * per-exercise, permanent note) and PracticeSession.note (a per-session
 * note, at most one per exercise per day, see SessionRecorder::finish()).
 * Not tied to a routine - a visitor only ever has one active routine at a
 * time anyway, so a routine-scoped note would just be a day-scoped note
 * with extra steps.
 */
#[ORM\Entity(repositoryClass: DayNoteRepository::class)]
#[ORM\UniqueConstraint(columns: ['visitor_id', 'date'])]
class DayNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::TEXT)]
    private string $note;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $visitorId, \DateTimeImmutable $date, string $note)
    {
        $this->visitorId = $visitorId;
        $this->date = $date->setTime(0, 0);
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
