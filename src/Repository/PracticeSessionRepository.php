<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
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

    /**
     * A still-running manual/external exercise session for this exact
     * exercise, if one already exists - used to resume it instead of
     * starting a second, overlapping one (which used to just pile up as an
     * orphaned "laufend" row nobody ever finished or discarded, since the
     * card only ever links to the most recently created one).
     */
    public function findRunningForExercise(string $visitorId, Exercise $exercise): ?PracticeSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.visitorId = :visitorId')
            ->andWhere('s.exercise = :exercise')
            ->andWhere('s.status = :status')
            ->setParameter('visitorId', $visitorId)
            ->setParameter('exercise', $exercise)
            ->setParameter('status', PracticeSession::STATUS_RUNNING)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Most recent finishedAt per exercise, across every visitor's finished
     * sessions - used by the daily staleness sweep (DeactivateStaleExercisesCommand),
     * which needs every exercise's true last-practiced date, not the
     * forVisitor()-style row cap tuned for rendering a single day.
     *
     * @return array<int, \DateTimeImmutable> exercise id => most recent finishedAt
     */
    public function latestFinishedAtByExercise(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.exercise) AS exerciseId', 'MAX(s.finishedAt) AS lastFinishedAt')
            ->andWhere('s.status = :status')
            ->andWhere('s.exercise IS NOT NULL')
            ->setParameter('status', PracticeSession::STATUS_FINISHED)
            ->groupBy('s.exercise')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['exerciseId']] = new \DateTimeImmutable($row['lastFinishedAt']);
        }

        return $map;
    }

    /**
     * The most recently started session for exactly this exercise+tool
     * pairing, regardless of status - configSnapshot is frozen at start
     * time either way, so even an abandoned/discarded round still has a
     * usable snapshot to prefill the settings page with next time.
     */
    public function mostRecentForExercise(Exercise $exercise, string $tool): ?PracticeSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exercise = :exercise')
            ->andWhere('s.tool = :tool')
            ->setParameter('exercise', $exercise)
            ->setParameter('tool', $tool)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The most recent session for this exercise that actually has a note -
     * shown on the running page as a reminder of what was noted last time,
     * regardless of how long ago or which session that was.
     */
    public function mostRecentNoteForExercise(Exercise $exercise): ?PracticeSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exercise = :exercise')
            ->andWhere('s.note IS NOT NULL')
            ->setParameter('exercise', $exercise)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * @return PracticeSession[] finished sessions for exactly this exercise, on exactly this date
     */
    public function finishedForExerciseOnDate(Exercise $exercise, \DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0);
        $end = $start->modify('+1 day');

        return $this->createQueryBuilder('s')
            ->andWhere('s.exercise = :exercise')
            ->andWhere('s.status = :status')
            ->andWhere('s.finishedAt >= :start AND s.finishedAt < :end')
            ->setParameter('exercise', $exercise)
            ->setParameter('status', PracticeSession::STATUS_FINISHED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PracticeSession[] finished sessions for this visitor and tool,
     *         most recent first, capped (same "recent window is enough"
     *         convention as forVisitor()) - diary "mark done" credit
     *         sessions (backdateStart()) always have tool = null in
     *         practice and so can never appear here regardless, but that's
     *         a fairness caveat worth keeping in mind since it's the same
     *         entity/table either way.
     */
    public function finishedForTool(string $visitorId, string $tool, int $limit = 200): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.visitorId = :visitorId')
            ->andWhere('s.tool = :tool')
            ->andWhere('s.status = :status')
            ->setParameter('visitorId', $visitorId)
            ->setParameter('tool', $tool)
            ->setParameter('status', PracticeSession::STATUS_FINISHED)
            ->orderBy('s.finishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
