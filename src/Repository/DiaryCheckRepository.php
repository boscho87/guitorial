<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiaryCheck;
use App\Entity\DiaryItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiaryCheck>
 */
class DiaryCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiaryCheck::class);
    }

    /**
     * @return DiaryCheck[] joins DiaryItem to scope by visitor - no
     *         denormalized visitorId column, same small-per-visitor-volume
     *         assumption PracticeSessionRepository::forVisitor already makes
     */
    public function forVisitorSince(string $visitorId, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.item', 'i')
            ->andWhere('i.visitorId = :visitorId')
            ->andWhere('c.checkedDate >= :since')
            ->setParameter('visitorId', $visitorId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    public function findForToday(DiaryItem $item, \DateTimeImmutable $today): ?DiaryCheck
    {
        return $this->findOneBy(['item' => $item, 'checkedDate' => $today]);
    }
}
