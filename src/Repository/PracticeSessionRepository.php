<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PracticeSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PracticeSession>
 */
class PracticeSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PracticeSession::class);
    }

    /**
     * @return PracticeSession[] most recent first
     */
    public function forVisitor(string $visitorId, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.visitorId = :visitorId')
            ->setParameter('visitorId', $visitorId)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PracticeSession[] one page, most recent first
     */
    public function forVisitorPage(string $visitorId, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.visitorId = :visitorId')
            ->setParameter('visitorId', $visitorId)
            ->orderBy('s.startedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PracticeSession[] running sessions for this tool that never
     *         received a single real interaction - safe to silently
     *         discard, there's no browser tab left to resume them
     */
    public function findRunningWithoutInteraction(string $visitorId, string $tool): array
    {
        return $this->findBy([
            'visitorId' => $visitorId,
            'tool' => $tool,
            'status' => PracticeSession::STATUS_RUNNING,
            'hasInteraction' => false,
        ]);
    }

    public function countForVisitor(string $visitorId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.visitorId = :visitorId')
            ->setParameter('visitorId', $visitorId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
