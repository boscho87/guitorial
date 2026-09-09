<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DiaryCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marks one DiaryItem as done on one calendar day - existence of the row IS
 * "checked", there is no boolean column, so unchecking just deletes it.
 * Only ever created for manual items (DiaryItem::isAutoTracked() === false)
 * and only for today; auto-tracked items never get a row here at all, their
 * state is always recomputed from practiced minutes instead.
 */
#[ORM\Entity(repositoryClass: DiaryCheckRepository::class)]
#[ORM\UniqueConstraint(columns: ['diary_item_id', 'checked_date'])]
class DiaryCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DiaryItem::class)]
    #[ORM\JoinColumn(name: 'diary_item_id', nullable: false)]
    private DiaryItem $item;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $checkedDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(DiaryItem $item, \DateTimeImmutable $checkedDate)
    {
        $this->item = $item;
        $this->checkedDate = $checkedDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): DiaryItem
    {
        return $this->item;
    }

    public function getCheckedDate(): \DateTimeImmutable
    {
        return $this->checkedDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
