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

    /**
     * @return DayNote[] every day note this visitor has, for the account
     *   data export (AccountDataExporter) - not used by any per-day view,
     *   which only ever needs forVisitorOnDate().
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->findBy(['visitorId' => $visitorId], ['date' => 'ASC']);
    }
}
