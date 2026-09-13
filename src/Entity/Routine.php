<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoutineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named, ordered list of existing Exercises the visitor wants to work
 * through as one unit. Deliberately holds no completion state of its own -
 * "done today" is derived the same way Exercise/ExerciseDayViewBuilder
 * already derives it (a finished PracticeSession for that exercise today),
 * so a Routine never becomes a second, parallel tracking system.
 */
#[ORM\Entity(repositoryClass: RoutineRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
class Routine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column]
    private int $sortIndex;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, RoutineItem> */
    #[ORM\OneToMany(mappedBy: 'routine', targetEntity: RoutineItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortIndex' => 'ASC'])]
    private Collection $items;

    public function __construct(string $visitorId, string $name, int $sortIndex)
    {
        $this->visitorId = $visitorId;
        $this->name = $name;
        $this->sortIndex = $sortIndex;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
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

    /**
     * @return Collection<int, RoutineItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function addItem(RoutineItem $item): void
    {
        $this->items->add($item);
    }

    public function removeItem(RoutineItem $item): void
    {
        $this->items->removeElement($item);
    }
}
