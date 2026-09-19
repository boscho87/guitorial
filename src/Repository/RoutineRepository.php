<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Routine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Routine>
 */
class RoutineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Routine::class);
    }

    /**
     * @return Routine[] ordered by sortIndex
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->findBy(['visitorId' => $visitorId], ['sortIndex' => 'ASC']);
    }

    public function countForVisitor(string $visitorId): int
    {
        return $this->count(['visitorId' => $visitorId]);
    }

    public function findForVisitor(int $id, string $visitorId): ?Routine
    {
        return $this->findOneBy(['id' => $id, 'visitorId' => $visitorId]);
    }

    public function findActiveForVisitor(string $visitorId): ?Routine
    {
        return $this->findOneBy(['visitorId' => $visitorId, 'isActive' => true]);
    }

    public function findByShareToken(string $token): ?Routine
    {
        return $this->findOneBy(['shareToken' => $token]);
    }

    /**
     * This visitor's own copy of $source, if they already imported it
     * before - a re-import reuses this instead of creating a second one.
     */
    public function findImportedFrom(string $visitorId, Routine $source): ?Routine
    {
        return $this->findOneBy(['visitorId' => $visitorId, 'importedFromRoutine' => $source]);
    }
}
