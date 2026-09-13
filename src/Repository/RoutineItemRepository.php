<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoutineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoutineItem>
 */
class RoutineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoutineItem::class);
    }
}
