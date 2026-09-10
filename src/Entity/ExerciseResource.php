<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseResourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One resource attached to an exercise - either one of the 5 internal
 * tools or an external HTTPS link, never both. Belongs to the Exercise
 * anchor, not the ExerciseVersion, so forking a version's title/group/
 * rhythm never duplicates or orphans its resources. A single table with
 * nullable variant columns rather than table-per-type, matching
 * PracticeSession's existing pattern (totalQuestions/correctCount only set
 * for graded tools).
 */
#[ORM\Entity(repositoryClass: ExerciseResourceRepository::class)]
#[ORM\Index(columns: ['exercise_id'])]
class ExerciseResource
{
    public const TYPE_INTERNAL_TOOL = 'internal_tool';
    public const TYPE_EXTERNAL_LINK = 'external_link';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false, onDelete: 'CASCADE')]
    private Exercise $exercise;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private int $sortIndex;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $internalToolId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $defaultConfigSnapshot = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $externalProvider = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $externalTitle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(Exercise $exercise, string $type, int $sortIndex)
    {
        $this->exercise = $exercise;
        $this->type = $type;
        $this->sortIndex = $sortIndex;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function internalTool(Exercise $exercise, int $sortIndex, string $toolId, ?array $defaultConfigSnapshot = null): self
    {
        $resource = new self($exercise, self::TYPE_INTERNAL_TOOL, $sortIndex);
        $resource->internalToolId = $toolId;
        $resource->defaultConfigSnapshot = $defaultConfigSnapshot;

        return $resource;
    }

    public static function externalLink(Exercise $exercise, int $sortIndex, string $url, ?string $provider = null, ?string $title = null): self
    {
        $resource = new self($exercise, self::TYPE_EXTERNAL_LINK, $sortIndex);
        $resource->externalUrl = $url;
        $resource->externalProvider = $provider;
        $resource->externalTitle = $title;

        return $resource;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isInternalTool(): bool
    {
        return $this->type === self::TYPE_INTERNAL_TOOL;
    }

    public function getSortIndex(): int
    {
        return $this->sortIndex;
    }

    public function getInternalToolId(): ?string
    {
        return $this->internalToolId;
    }

    public function getDefaultConfigSnapshot(): ?array
    {
        return $this->defaultConfigSnapshot;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function getExternalProvider(): ?string
    {
        return $this->externalProvider;
    }

    public function getExternalTitle(): ?string
    {
        return $this->externalTitle;
    }

    public function applyExternalMetadata(?string $provider, ?string $title): void
    {
        $this->externalProvider = $provider;
        $this->externalTitle = $title;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
