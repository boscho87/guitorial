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

    /**
     * No single session is ever credited longer than this, no matter what -
     * a hard backstop behind the heartbeat-based cap below (see finish()),
     * in case a session type never gets a heartbeat at all (e.g. JS
     * disabled). Same order of magnitude as DiaryController::MAX_MINUTES,
     * the largest target minutes a visitor can even set.
     */
    private const MAX_SESSION_MINUTES = 180;

    /**
     * How much later than the last confirmed "still there" signal
     * (updatedAt, bumped by a heartbeat or a tool round's practice-sync)
     * finish() is still allowed to land, so a short session that finishes
     * before its very first heartbeat ever fires is never wrongly clipped -
     * deliberately more generous than any heartbeat interval in this app.
     */
    private const HEARTBEAT_GRACE_SECONDS = 90;

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

    /**
     * Points-based personal record for this round (10 per correct answer,
     * plus timeBonusPoints below on a perfect round). Null for anything
     * that predates this feature or was never graded (skalen-generator,
     * diary manual completions) - excluded from record comparisons rather
     * than treated as zero.
     */
    #[ORM\Column(nullable: true)]
    private ?int $points = null;

    #[ORM\Column(nullable: true)]
    private ?int $timeBonusPoints = null;

    /**
     * Free-text note about this one Durchführung - distinct from
     * Exercise::$note (the durable, never-forked note about the exercise
     * itself). Set once at finish() time, same as learningState/points;
     * never retroactively edited.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

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

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function getTimeBonusPoints(): ?int
    {
        return $this->timeBonusPoints;
    }

    public function getNote(): ?string
    {
        return $this->note;
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

    public function finish(?string $learningState = null, ?int $points = null, ?int $timeBonusPoints = null, ?string $note = null): void
    {
        $this->finishedAt = $this->cappedFinishTimestamp();
        $this->status = self::STATUS_FINISHED;
        $this->learningState = $learningState;
        $this->points = $points;
        $this->timeBonusPoints = $timeBonusPoints;
        $this->note = $note;
    }

    /**
     * Bumps only updatedAt - a lightweight heartbeat ("still there") signal,
     * distinct from applySync()'s fuller question-progress semantics. Used
     * by manual/external exercise sessions, which have no other sync
     * mechanism at all.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A session left untouched for longer than the hard finish() cap isn't
     * "paused", it's abandoned - resuming it (Fortsetzen, or a heartbeat
     * firing again after e.g. the laptop woke up) rebases startedAt/
     * updatedAt to now instead of letting the live clock (and eventually
     * cappedFinishTimestamp()'s hard cap, which is always anchored to the
     * original startedAt) reflect the entire abandoned gap. A shorter gap
     * is already handled correctly by the existing heartbeat-grace cap
     * above, so this only ever fires well beyond that, never for a normal
     * brief pause. Returns whether it actually changed anything, so the
     * caller only flushes when needed.
     */
    public function rebaseIfAbandoned(): bool
    {
        $now = new \DateTimeImmutable();
        if ($now->getTimestamp() - $this->updatedAt->getTimestamp() <= self::MAX_SESSION_MINUTES * 60) {
            return false;
        }

        $this->startedAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * How many seconds of this session are creditable right now, capped
     * exactly the way finish() ultimately caps the persisted duration -
     * but callable at any time, not just at finish(). Lets the running
     * page's live clock anchor itself on real practiced time instead of
     * raw wall-clock since the original start, so navigating away (back
     * to Heute) and returning later doesn't make the clock look like it
     * kept running the whole time you were gone.
     */
    public function creditableSecondsElapsed(): int
    {
        return $this->cappedFinishTimestamp()->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Never credits a session past whichever is most trustworthy - a tab
     * abandoned for hours (closed laptop, forgotten background tab, ...)
     * and only finished much later must not credit that entire real-world
     * gap. Timestamp arithmetic (not DateTimeImmutable comparison) to match
     * the same pattern already used for the live-progress cap in
     * ExerciseTargetProgress::calculate().
     *
     * Two different cases, deliberately not just min()'d together: if a
     * heartbeat actually arrived at least once, it's the more precise
     * signal and gets a tight grace period past its last ping. But if none
     * ever arrived - JS disabled, or finish() simply came before the first
     * ~20s tick - there is no such signal to trust, and clipping to that
     * same tight grace would wrongly punish a genuinely long session whose
     * heartbeat mechanism just never ran; only the much looser hard
     * ceiling applies then.
     */
    private function cappedFinishTimestamp(): \DateTimeImmutable
    {
        $now = time();
        $startedAtSeconds = $this->startedAt->getTimestamp();
        $updatedAtSeconds = $this->updatedAt->getTimestamp();
        $hardCapSeconds = $startedAtSeconds + self::MAX_SESSION_MINUTES * 60;

        if ($updatedAtSeconds > $startedAtSeconds) {
            $referenceSeconds = $updatedAtSeconds + self::HEARTBEAT_GRACE_SECONDS;

            return (new \DateTimeImmutable())->setTimestamp(min($now, $referenceSeconds, $hardCapSeconds));
        }

        return (new \DateTimeImmutable())->setTimestamp(min($now, $hardCapSeconds));
    }

    /**
     * For a manual "mark done" credit on a minute-target exercise: an
     * instantly start()+finish()'d session has ~0 elapsed minutes, which
     * could never satisfy a target of e.g. 30 - backdating startedAt makes
     * the credited duration exactly the target, same as if it had really
     * been practiced that long.
     */
    public function backdateStart(int $minutes): void
    {
        $this->startedAt = $this->startedAt->modify(sprintf('-%d minutes', $minutes));
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
