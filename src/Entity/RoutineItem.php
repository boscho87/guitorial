<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoutineItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoutineItemRepository::class)]
class RoutineItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Routine::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Routine $routine;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Exercise $exercise;

    #[ORM\Column]
    private int $sortIndex;

    public function __construct(Routine $routine, Exercise $exercise, int $sortIndex)
    {
        $this->routine = $routine;
        $this->exercise = $exercise;
        $this->sortIndex = $sortIndex;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoutine(): Routine
    {
        return $this->routine;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getSortIndex(): int
    {
        return $this->sortIndex;
    }

    public function moveTo(int $sortIndex): void
    {
        $this->sortIndex = $sortIndex;
    }
}
