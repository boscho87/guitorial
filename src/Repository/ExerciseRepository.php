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
}
