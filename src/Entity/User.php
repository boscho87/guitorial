<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\History\HistoryStatsBuilder;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An optional account, layered on top of the anonymous visitorId scheme
 * rather than replacing it - see AccountLinker for how a User "claims" a
 * visitorId, and why no other entity gets a user_id column. OAuth-only (no
 * password), so this deliberately doesn't implement
 * PasswordAuthenticatedUserInterface.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 36, unique: true)]
    private string $visitorId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Set by SendStreakRemindersCommand once it actually sends - keeps a
     * manual re-run of the command on the same day from double-sending,
     * since the cron is the only thing expected to invoke it.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastStreakReminderSentAt = null;

    /**
     * Flipped either via the profile settings toggle or the one-click
     * unsubscribe link in a reminder mail (UnsubscribeController, no login
     * required there - the signed token is what proves the request).
     * Defaults to false (reminders on) - see SendStreakRemindersCommand.
     */
    #[ORM\Column]
    private bool $streakReminderOptOut = false;

    /**
     * Opt-in (default off, unlike streakReminderOptOut) - a daily mail is a
     * bigger commitment than the occasional streak warning, so this one
     * only goes out once someone actively turns it on in their profile.
     * See SendDailyDigestCommand.
     */
    #[ORM\Column]
    private bool $dailyDigestEnabled = false;

    /**
     * How many missing days HistoryStatsBuilder::currentStreak() tolerates
     * before the streak actually breaks (profile setting, 0-3). Defaults to
     * the same value as HistoryStatsBuilder::DEFAULT_GRACE_DAYS, the one
     * anonymous visitors always get (they have no profile to change it in),
     * so a brand-new account's streak behaves identically until they
     * deliberately tighten it.
     */
    #[ORM\Column]
    private int $streakGraceDays = HistoryStatsBuilder::DEFAULT_GRACE_DAYS;

    public function __construct(string $email, string $firstName, string $lastName, string $visitorId)
    {
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->visitorId = $visitorId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastStreakReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->lastStreakReminderSentAt;
    }

    public function markStreakReminderSent(\DateTimeImmutable $today): void
    {
        $this->lastStreakReminderSentAt = $today;
    }

    public function hasOptedOutOfStreakReminders(): bool
    {
        return $this->streakReminderOptOut;
    }

    public function optOutOfStreakReminders(): void
    {
        $this->streakReminderOptOut = true;
    }

    public function optInToStreakReminders(): void
    {
        $this->streakReminderOptOut = false;
    }

    public function hasDailyDigestEnabled(): bool
    {
        return $this->dailyDigestEnabled;
    }

    public function enableDailyDigest(): void
    {
        $this->dailyDigestEnabled = true;
    }

    public function disableDailyDigest(): void
    {
        $this->dailyDigestEnabled = false;
    }

    public function getStreakGraceDays(): int
    {
        return $this->streakGraceDays;
    }

    public function setStreakGraceDays(int $days): void
    {
        $this->streakGraceDays = max(0, min(3, $days));
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // No password/credentials stored - nothing to erase.
    }
}
