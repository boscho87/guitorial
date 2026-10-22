<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exercise;
use PHPUnit\Framework\TestCase;

final class ExerciseTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-10-22');
    }

    public function testFreshExerciseIsActiveAndNotDeactivated(): void
    {
        $exercise = new Exercise('visitor-1');

        self::assertTrue($exercise->isActiveOn($this->today));
        self::assertFalse($exercise->isDeactivatedOn($this->today));
    }

    public function testDeactivateMakesItInactiveButNotArchived(): void
    {
        $exercise = new Exercise('visitor-1');
        $exercise->deactivate($this->today);

        self::assertFalse($exercise->isActiveOn($this->today));
        self::assertTrue($exercise->isDeactivatedOn($this->today));
    }

    public function testReactivateFromStalenessRestoresActive(): void
    {
        $exercise = new Exercise('visitor-1');
        $exercise->deactivate($this->today);
        $exercise->reactivateFromStaleness();

        self::assertTrue($exercise->isActiveOn($this->today));
        self::assertFalse($exercise->isDeactivatedOn($this->today));
    }

    public function testArchiveTakesPrecedenceOverDeactivation(): void
    {
        $exercise = new Exercise('visitor-1');
        $exercise->deactivate($this->today);
        $exercise->archive($this->today);

        self::assertFalse($exercise->isActiveOn($this->today));
        self::assertFalse($exercise->isDeactivatedOn($this->today), 'archived takes precedence - must not also report as deactivated');
    }

    public function testArchivedExerciseIsNeitherActiveNorDeactivated(): void
    {
        $exercise = new Exercise('visitor-1');
        $exercise->archive($this->today);

        self::assertFalse($exercise->isActiveOn($this->today));
        self::assertFalse($exercise->isDeactivatedOn($this->today));
    }

    public function testReactivateClearsArchiveButLeavesDeactivationUntouched(): void
    {
        $exercise = new Exercise('visitor-1');
        $exercise->deactivate($this->today);
        $exercise->archive($this->today);
        $exercise->reactivate();

        self::assertFalse($exercise->isActiveOn($this->today), 'still deactivated, just no longer archived');
        self::assertTrue($exercise->isDeactivatedOn($this->today));
    }
}
