<?php

declare(strict_types=1);

namespace App\Domain\Diary;

use App\Entity\ExerciseGroup;
use App\Repository\ExerciseGroupRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Get-or-create for a visitor's two system groups (Theorie/Praxis) - "keine
 * Gruppe" is not a state an exercise can be in, so every exercise that
 * doesn't get an explicit custom group ends up in one of these instead.
 * Deliberately looked up by `kind`, not by name, so a visitor renaming
 * "Theorie" to something else doesn't break re-provisioning; if a system
 * group gets deleted entirely, the next exercise that needs it just gets a
 * fresh one created (no protected/undeletable special-casing needed).
 */
final class ExerciseGroupProvisioner
{
    private const LABELS = [
        ExerciseGroup::KIND_THEORY => 'Theorie',
        ExerciseGroup::KIND_PRACTICE => 'Praxis',
    ];

    public function __construct(
        private readonly ExerciseGroupRepository $groups,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * True if $name (trimmed, case-insensitive) collides with a system
     * group's reserved label - a custom group must never be allowed this
     * name, since it would then look indistinguishable from the real system
     * group in the UI (and deleting either one re-provisions a fresh
     * "Praxis"/"Theorie", which looks like the deletion silently failed).
     * $exceptKind lets a system group keep/reclaim its own label when
     * renaming.
     */
    public function isReservedLabel(string $name, ?string $exceptKind = null): bool
    {
        $normalized = mb_strtolower(trim($name));
        foreach (self::LABELS as $kind => $label) {
            if ($kind !== $exceptKind && mb_strtolower($label) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function systemGroupFor(string $visitorId, bool $isToolLinked): ExerciseGroup
    {
        $kind = $isToolLinked ? ExerciseGroup::KIND_THEORY : ExerciseGroup::KIND_PRACTICE;

        $existing = $this->groups->findByKind($visitorId, $kind);
        if ($existing !== null) {
            return $existing;
        }

        $group = new ExerciseGroup($visitorId, self::LABELS[$kind], $this->groups->countForVisitor($visitorId), $kind);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }
}
