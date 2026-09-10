<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The versioned detail of an Exercise (title, group, target time, repeat
 * rhythm) - a date-range row exactly like the old DiaryItem, so a day D's
 * plan resolves to whichever version's range covered it, forever, even
 * after later edits. See ExerciseVersionRepository::edit() for the
 * mutate-in-place-if-today vs. close-and-fork rule.
 */
#[ORM\Entity(repositoryClass: ExerciseVersionRepository::class)]
#[ORM\Index(columns: ['exercise_id'])]
#[ORM\Index(columns: ['exercise_id', 'valid_from', 'valid_until'])]
class ExerciseVersion
{
    public const RHYTHM_DAILY = 'taeglich';
    public const RHYTHM_WEEKLY = 'pro_woche';
    public const RHYTHM_NONE = 'ohne_vorgabe';
    public const RHYTHMS = [self::RHYTHM_DAILY, self::RHYTHM_WEEKLY, self::RHYTHM_NONE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false, onDelete: 'CASCADE')]
    private Exercise $exercise;

    #[ORM\ManyToOne(targetEntity: ExerciseGroup::class)]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'SET NULL')]
    private ?ExerciseGroup $group = null;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(nullable: true)]
    private ?int $targetMinutes = null;

    #[ORM\Column(length: 20)]
    private string $repeatRhythm;

    #[ORM\Column(nullable: true)]
    private ?int $repeatCount = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Exercise $exercise,
        string $title,
        ?ExerciseGroup $group,
        ?int $targetMinutes,
        string $repeatRhythm,
        ?int $repeatCount,
        \DateTimeImmutable $validFrom,
    ) {
        $this->exercise = $exercise;
        $this->title = $title;
        $this->group = $group;
        $this->targetMinutes = $targetMinutes;
        $this->repeatRhythm = $repeatRhythm;
        $this->repeatCount = $repeatCount;
        $this->validFrom = $validFrom;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getGroup(): ?ExerciseGroup
    {
        return $this->group;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTargetMinutes(): ?int
    {
        return $this->targetMinutes;
    }

    public function getRepeatRhythm(): string
    {
        return $this->repeatRhythm;
    }

    public function getRepeatCount(): ?int
    {
        return $this->repeatCount;
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

    public function closeAsOf(\DateTimeImmutable $today): void
    {
        $this->validUntil = $today;
    }

    /**
     * Only valid to call when getValidFrom() == $today - see
     * ExerciseVersionRepository::edit(), the only caller.
     */
    public function applyEdit(string $title, ?ExerciseGroup $group, ?int $targetMinutes, string $repeatRhythm, ?int $repeatCount): void
    {
        $this->title = $title;
        $this->group = $group;
        $this->targetMinutes = $targetMinutes;
        $this->repeatRhythm = $repeatRhythm;
        $this->repeatCount = $repeatCount;
    }
}
