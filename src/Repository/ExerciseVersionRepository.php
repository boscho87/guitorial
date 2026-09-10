<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\ExerciseGroup;
use App\Entity\ExerciseVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseVersion>
 */
class ExerciseVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseVersion::class);
    }

    /**
     * @return ExerciseVersion[] every version of every exercise this visitor has
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->createQueryBuilder('v')
            ->join('v.exercise', 'e')
            ->andWhere('e.visitorId = :visitorId')
            ->setParameter('visitorId', $visitorId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Applies the same temporal versioning rule DiaryItemRepository::edit()
     * established: a version whose validFrom is today is mutated in place
     * (it never applied to a past day yet); an already-historical version
     * is closed as of today and replaced by a new one effective today, so
     * past days keep exactly what they looked like.
     */
    public function edit(
        ExerciseVersion $version,
        string $title,
        ?ExerciseGroup $group,
        ?int $targetMinutes,
        string $repeatRhythm,
        ?int $repeatCount,
        \DateTimeImmutable $today,
    ): ExerciseVersion {
        if ($version->getValidFrom()->format('Y-m-d') === $today->format('Y-m-d')) {
            $version->applyEdit($title, $group, $targetMinutes, $repeatRhythm, $repeatCount);

            return $version;
        }

        $version->closeAsOf($today);
        $successor = new ExerciseVersion($version->getExercise(), $title, $group, $targetMinutes, $repeatRhythm, $repeatCount, $today);
        $this->getEntityManager()->persist($successor);

        return $successor;
    }

    public function currentFor(Exercise $exercise, \DateTimeImmutable $today): ?ExerciseVersion
    {
        foreach ($this->findBy(['exercise' => $exercise]) as $version) {
            if ($version->isActiveOn($today)) {
                return $version;
            }
        }

        return null;
    }
}
