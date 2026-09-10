<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExerciseGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseGroup>
 */
class ExerciseGroupRepository extends ServiceEntityRepository
{
    public const MAX_GROUPS_PER_VISITOR = 6;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseGroup::class);
    }

    /**
     * @return ExerciseGroup[] ordered by sortIndex
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->findBy(['visitorId' => $visitorId], ['sortIndex' => 'ASC']);
    }

    public function countForVisitor(string $visitorId): int
    {
        return $this->count(['visitorId' => $visitorId]);
    }

    public function findForVisitor(int $id, string $visitorId): ?ExerciseGroup
    {
        return $this->findOneBy(['id' => $id, 'visitorId' => $visitorId]);
    }
}
