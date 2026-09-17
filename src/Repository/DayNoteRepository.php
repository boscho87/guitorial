<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DayNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DayNote>
 */
class DayNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DayNote::class);
    }

    public function forVisitorOnDate(string $visitorId, \DateTimeImmutable $date): ?DayNote
    {
        return $this->findOneBy(['visitorId' => $visitorId, 'date' => $date->setTime(0, 0)]);
    }
}
