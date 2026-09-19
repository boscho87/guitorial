<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttempt>
 */
class QuizAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttempt::class);
    }

    public function record(string $tool, string $questionKey, bool $correct, string $visitorId): void
    {
        $em = $this->getEntityManager();
        $em->persist(new QuizAttempt($tool, $questionKey, $correct, $visitorId));
        $em->flush();
    }

    /**
     * Number of wrong attempts per question key, for the given tool and
     * visitor - used to weight which questions come up more often, so each
     * visitor's own mistakes shape their own practice.
     *
     * @return array<string, int>
     */
    public function wrongCountsByQuestionKey(string $tool, string $visitorId): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.questionKey AS questionKey', 'COUNT(a.id) AS wrongCount')
            ->andWhere('a.tool = :tool')
            ->andWhere('a.visitorId = :visitorId')
            ->andWhere('a.correct = false')
            ->setParameter('tool', $tool)
            ->setParameter('visitorId', $visitorId)
            ->groupBy('a.questionKey')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['questionKey']] = (int) $row['wrongCount'];
        }

        return $counts;
    }

    /**
     * @return array{attempts: int, correct: int, streak: int}
     */
    public function summary(string $tool): array
    {
        /** @var QuizAttempt[] $recent */
        $recent = $this->createQueryBuilder('a')
            ->andWhere('a.tool = :tool')
            ->setParameter('tool', $tool)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $attempts = count($recent);
        $correct = 0;
        $streak = 0;
        $streakBroken = false;

        foreach ($recent as $attempt) {
            if ($attempt->isCorrect()) {
                $correct++;
                if (!$streakBroken) {
                    $streak++;
                }
            } else {
                $streakBroken = true;
            }
        }

        return ['attempts' => $attempts, 'correct' => $correct, 'streak' => $streak];
    }
}
