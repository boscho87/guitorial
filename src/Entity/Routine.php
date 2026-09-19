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
 *
 * At most one Routine per visitor is ever `isActive` - that one determines
 * what "Heute" shows (see DiaryController::index()), instead of Heute
 * always listing every rhythm-due exercise regardless of grouping.
 * Enforced in the controller action (deactivate any other before
 * activating this one), not a DB constraint.
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

    #[ORM\Column]
    private bool $isActive = false;

    /**
     * Generated lazily (ensureShareToken()) only once someone actually
     * clicks "Teilen" - null until then, rather than every Routine getting
     * a guessable public link the moment it's created.
     */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $shareToken = null;

    /**
     * Set only by RoutineShareController::import() - a re-import of the
     * same shared link reuses this exact copy instead of creating a
     * second one.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'imported_from_routine_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $importedFromRoutine = null;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function ensureShareToken(): string
    {
        if ($this->shareToken === null) {
            $this->shareToken = bin2hex(random_bytes(8));
        }

        return $this->shareToken;
    }

    public function getImportedFromRoutine(): ?self
    {
        return $this->importedFromRoutine;
    }

    public function markImportedFrom(self $source): void
    {
        $this->importedFromRoutine = $source;
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
