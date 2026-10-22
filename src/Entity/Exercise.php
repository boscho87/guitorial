<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The stable anchor for one exercise - holds exactly the fields the spec
 * says must NOT be versioned (the note) and the fields that must survive a
 * version fork (resources, via ExerciseResource pointing at the anchor, not
 * the version). Title/group/target-time/rhythm live on ExerciseVersion
 * instead, which forks the same way DiaryItem used to (see
 * ExerciseVersionRepository::edit()) - splitting anchor from version is
 * what lets a fork keep the note and resources without duplicating or
 * losing them.
 *
 * Archived (removed from the plan but history kept) is modeled as
 * `archivedAt` rather than a separate status column, so "is this exercise
 * part of the plan on day D" stays a single half-open interval check
 * (`archivedAt === null || D < archivedAt`), the same shape as
 * DiaryItem::isActiveOn() - no redundant derived state to keep in sync.
 *
 * `deactivatedAt` is a second, independent half-open interval of the same
 * shape, set automatically by DeactivateStaleExercisesCommand (10 days
 * without a finished session) rather than by the visitor - archiving stays
 * a deliberate, manual, final action, while deactivation is just "gone
 * quiet", reversible by nothing more than practicing the exercise again
 * (SessionRecorder::start() clears it). Archived wins if somehow both are
 * set: see isActiveOn()/isDeactivatedOn().
 */
#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Index(columns: ['visitor_id'])]
class Exercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /**
     * This exercise's own remembered metronome/drone setup (bpm, beats,
     * droneNote, fifth, octave - not the volumes, those are a listening-
     * environment preference, not an exercise one) - lives on the anchor
     * like the note, not the version: a practice setup you've settled into
     * for this exercise, not something that should fork on edit or stay
     * tied to history. Loaded into the global practice-tools widget
     * (data-turbo-permanent, see base.html.twig) when its running page
     * connects, but deliberately never auto-played from here - see
     * practice_tools_controller.js's bindExercise().
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $practiceToolsSnapshot = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /**
     * Set only by RoutineShareController::import() - lets a re-import
     * reuse this exact copy instead of cloning a duplicate, and survives
     * the copy being renamed (unlike matching by title).
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'imported_from_exercise_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $importedFromExercise = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $visitorId)
    {
        $this->visitorId = $visitorId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getPracticeToolsSnapshot(): ?array
    {
        return $this->practiceToolsSnapshot;
    }

    public function applyPracticeToolsSnapshot(array $snapshot): void
    {
        $this->practiceToolsSnapshot = $snapshot;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function getImportedFromExercise(): ?self
    {
        return $this->importedFromExercise;
    }

    public function markImportedFrom(self $source): void
    {
        $this->importedFromExercise = $source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        $archived = $this->archivedAt !== null && $date >= $this->archivedAt;
        $deactivated = $this->deactivatedAt !== null && $date >= $this->deactivatedAt;

        return !$archived && !$deactivated;
    }

    /**
     * Only true for the "gone quiet" state, never for an archived exercise
     * (archived takes precedence - it's the more deliberate, final one).
     * Used by DiaryController::manage() to split the three tabs.
     */
    public function isDeactivatedOn(\DateTimeImmutable $date): bool
    {
        $archived = $this->archivedAt !== null && $date >= $this->archivedAt;

        return !$archived && $this->deactivatedAt !== null && $date >= $this->deactivatedAt;
    }

    /**
     * The note belongs to the anchor, never the version - it's always
     * editable and never forked, exactly as specified ("keine eigene
     * Versionshistorie").
     */
    public function applyNote(?string $note): void
    {
        $this->note = $note;
    }

    public function archive(\DateTimeImmutable $today): void
    {
        $this->archivedAt = $today;
    }

    public function reactivate(): void
    {
        $this->archivedAt = null;
    }

    public function deactivate(\DateTimeImmutable $today): void
    {
        $this->deactivatedAt = $today;
    }

    public function reactivateFromStaleness(): void
    {
        $this->deactivatedAt = null;
    }
}
