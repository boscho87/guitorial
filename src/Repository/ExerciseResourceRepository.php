<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\ExerciseResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseResource>
 */
class ExerciseResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseResource::class);
    }

    /**
     * @return ExerciseResource[] ordered by sortIndex
     */
    public function forExercise(Exercise $exercise): array
    {
        return $this->findBy(['exercise' => $exercise], ['sortIndex' => 'ASC']);
    }
}
