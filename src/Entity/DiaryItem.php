<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DiaryItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One checklist item in a visitor's practice diary. Versioned by date range
 * rather than a separate snapshot table: editing an item that has already
 * applied to a past day closes it (`validUntil` = today) and inserts a new
 * row effective today, so a past day's checklist always resolves to
 * whichever row's range covered it - editing never rewrites history, but an
 * edit made at any point today is immediately visible today too, since the
 * old row's range excludes today once closed. Scoped to the same anonymous
 * visitorId cookie as PracticeSession.
 */
#[ORM\Entity(repositoryClass: DiaryItemRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
#[ORM\Index(columns: ['visitor_id', 'valid_from', 'valid_until'])]
class DiaryItem
{
    public const CATEGORY_THEORY = 'theorie';
    public const CATEGORY_PRACTICE = 'praxis';
    public const CATEGORIES = [self::CATEGORY_THEORY, self::CATEGORY_PRACTICE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $linkedTool = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetMinutes = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $visitorId,
        string $label,
        string $category,
        ?string $linkedTool,
        ?int $targetMinutes,
        \DateTimeImmutable $validFrom,
    ) {
        $this->visitorId = $visitorId;
        $this->label = $label;
        $this->category = $category;
        $this->linkedTool = $linkedTool;
        $this->targetMinutes = $targetMinutes;
        $this->validFrom = $validFrom;
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getLinkedTool(): ?string
    {
        return $this->linkedTool;
    }

    public function getTargetMinutes(): ?int
    {
        return $this->targetMinutes;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        return $this->validFrom <= $date && ($this->validUntil === null || $date < $this->validUntil);
    }

    public function isAutoTracked(): bool
    {
        return $this->linkedTool !== null && $this->targetMinutes !== null;
    }

    public function closeAsOf(\DateTimeImmutable $today): void
    {
        $this->validUntil = $today;
    }

    /**
     * Only valid to call when getValidFrom() == $today - see
     * DiaryItemRepository::edit(), which is the only caller.
     */
    public function applyEdit(string $label, string $category, ?string $linkedTool, ?int $targetMinutes): void
    {
        $this->label = $label;
        $this->category = $category;
        $this->linkedTool = $linkedTool;
        $this->targetMinutes = $targetMinutes;
    }
}
