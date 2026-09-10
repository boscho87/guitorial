<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PracticeSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One practice round's result, synced from the browser (on start, every
 * few seconds, and on completion) so progress survives even if the tab
 * closes mid-round. Scoped to an anonymous visitorId cookie for now - once
 * accounts exist, sessions for a visitorId get reassigned to the user that
 * logs in with it, rather than migrated/copied.
 *
 * This entity plays THREE roles at once, on purpose (see CLAUDE.md's
 * "Anonymous history/sync" section and the exercise-diary planning doc for
 * the full reasoning) - keep them straight rather than reaching for a
 * fourth parallel concept:
 * 1. The original one: a lazily-synced record of one of the 5 internal
 *    tools' rounds, feeding /verlauf and the diary's auto-check-by-minutes.
 * 2. Now also: the authoritative, persisted "session" for the exercise
 *    diary - created synchronously at start with status=laufend, resumable,
 *    optionally linked to a plan Exercise/ExerciseResource, carrying an
 *    immutable snapshot of whatever tool config or resource was actually
 *    used.
 * 3. Still NOT: the per-question-answer weighting heuristic - that's the
 *    separate, visitor-independent QuizAttempt/WeightedQuestionPicker,
 *    deliberately left untouched by any of this.
 */
#[ORM\Entity(repositoryClass: PracticeSessionRepository::class)]
#[ORM\UniqueConstraint(columns: ['visitor_id', 'client_session_id'])]
#[ORM\Index(columns: ['visitor_id'])]
class PracticeSession
{
    public const STATUS_RUNNING = 'laufend';
    public const STATUS_FINISHED = 'abgeschlossen';
    public const STATUS_DISCARDED = 'verworfen';

    public const LEARNING_STATE_DIFFICULT = 'schwierig';
    public const LEARNING_STATE_IMPROVING = 'wird_besser';
    public const LEARNING_STATE_CONFIDENT = 'sicher';
    public const LEARNING_STATES = [self::LEARNING_STATE_DIFFICULT, self::LEARNING_STATE_IMPROVING, self::LEARNING_STATE_CONFIDENT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $visitorId;

    #[ORM\Column(length: 36)]
    private string $clientSessionId;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $tool = null;

    #[ORM\Column]
    private int $questionIndex = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalQuestions = null;

    #[ORM\Column(nullable: true)]
    private ?int $correctCount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: true, onDelete: 'CASCADE')]
    private ?Exercise $exercise = null;

    #[ORM\ManyToOne(targetEntity: ExerciseResource::class)]
    #[ORM\JoinColumn(name: 'resource_id', nullable: true, onDelete: 'SET NULL')]
    private ?ExerciseResource $resource = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $configSnapshot = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $learningState = null;

    #[ORM\Column]
    private bool $hasInteraction = false;

    public function __construct(
        string $visitorId,
        string $clientSessionId,
        ?string $tool = null,
        ?Exercise $exercise = null,
        ?ExerciseResource $resource = null,
        ?array $configSnapshot = null,
    ) {
        $this->visitorId = $visitorId;
        $this->clientSessionId = $clientSessionId;
        $this->tool = $tool;
        $this->exercise = $exercise;
        $this->resource = $resource;
        $this->configSnapshot = $configSnapshot;
        $this->startedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getClientSessionId(): string
    {
        return $this->clientSessionId;
    }

    public function getTool(): ?string
    {
        return $this->tool;
    }

    public function getQuestionIndex(): int
    {
        return $this->questionIndex;
    }

    public function getTotalQuestions(): ?int
    {
        return $this->totalQuestions;
    }

    public function getCorrectCount(): ?int
    {
        return $this->correctCount;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function getExercise(): ?Exercise
    {
        return $this->exercise;
    }

    public function getResource(): ?ExerciseResource
    {
        return $this->resource;
    }

    public function getConfigSnapshot(): ?array
    {
        return $this->configSnapshot;
    }

    public function getLearningState(): ?string
    {
        return $this->learningState;
    }

    public function hasInteraction(): bool
    {
        return $this->hasInteraction;
    }

    /**
     * Marks the point a round stopped being "just configured" and became
     * something the visitor actually did - see each controller's own
     * definition of "first real interaction". Governs whether an
     * unfinished, tab-closed session may be silently discarded (never
     * marked) or must be offered as resume/discard (marked at least once).
     */
    public function markInteraction(): void
    {
        $this->hasInteraction = true;
    }

    public function finish(?string $learningState = null): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_FINISHED;
        $this->learningState = $learningState;
    }

    public function discard(): void
    {
        $this->status = self::STATUS_DISCARDED;
    }

    public function applySync(int $questionIndex, ?int $totalQuestions, ?int $correctCount, bool $finished): void
    {
        $this->questionIndex = $questionIndex;
        $this->totalQuestions = $totalQuestions;
        $this->correctCount = $correctCount;
        $this->updatedAt = new \DateTimeImmutable();
        if ($finished && $this->finishedAt === null) {
            $this->finishedAt = $this->updatedAt;
            $this->status = self::STATUS_FINISHED;
        }
    }
}
