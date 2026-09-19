<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /**
     * @return Exercise[] every exercise anchor this visitor has, active or archived
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->findBy(['visitorId' => $visitorId]);
    }

    public function findForVisitor(int $id, string $visitorId): ?Exercise
    {
        return $this->findOneBy(['id' => $id, 'visitorId' => $visitorId]);
    }

    /**
     * This visitor's own copy of $source, if they already imported it via
     * a shared routine before - lets a re-import reuse it instead of
     * cloning a duplicate.
     */
    public function findImportedFrom(string $visitorId, Exercise $source): ?Exercise
    {
        return $this->findOneBy(['visitorId' => $visitorId, 'importedFromExercise' => $source]);
    }
}
