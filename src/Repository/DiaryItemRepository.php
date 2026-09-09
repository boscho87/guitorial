<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiaryItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiaryItem>
 */
class DiaryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiaryItem::class);
    }

    /**
     * @return DiaryItem[] every version this visitor ever had
     */
    public function forVisitor(string $visitorId): array
    {
        return $this->findBy(['visitorId' => $visitorId]);
    }

    public function findForVisitor(int $id, string $visitorId): ?DiaryItem
    {
        return $this->findOneBy(['id' => $id, 'visitorId' => $visitorId]);
    }

    /**
     * Applies the temporal versioning rule: an item whose current validFrom
     * is today is mutated in place (it never applied to a past day yet); an
     * already-historical item is closed as of today and replaced by a new
     * version effective today, so past days keep exactly what they looked
     * like. Persists the successor but never flushes - caller's job.
     */
    public function edit(
        DiaryItem $item,
        string $label,
        string $category,
        ?string $linkedTool,
        ?int $targetMinutes,
        \DateTimeImmutable $today,
    ): DiaryItem {
        if ($item->getValidFrom()->format('Y-m-d') === $today->format('Y-m-d')) {
            $item->applyEdit($label, $category, $linkedTool, $targetMinutes);

            return $item;
        }

        $item->closeAsOf($today);
        $successor = new DiaryItem($item->getVisitorId(), $label, $category, $linkedTool, $targetMinutes, $today);
        $this->getEntityManager()->persist($successor);

        return $successor;
    }

    /**
     * Same in-place-vs-close rule as edit(): hard-delete if validFrom ==
     * today (never applied to any past day), else just close as of today.
     */
    public function remove(DiaryItem $item, \DateTimeImmutable $today): void
    {
        if ($item->getValidFrom()->format('Y-m-d') === $today->format('Y-m-d')) {
            $this->getEntityManager()->remove($item);

            return;
        }

        $item->closeAsOf($today);
    }
}
