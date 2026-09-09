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

    public function findOrCreate(string $visitorId, string $clientSessionId, string $tool): PracticeSession
    {
        $existing = $this->findOneBy(['visitorId' => $visitorId, 'clientSessionId' => $clientSessionId]);
        if ($existing !== null) {
            return $existing;
        }

        $session = new PracticeSession($visitorId, $clientSessionId, $tool);
        $this->getEntityManager()->persist($session);

        return $session;
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
}
