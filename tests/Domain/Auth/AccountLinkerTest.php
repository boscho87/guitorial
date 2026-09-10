<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Auth\AccountLinker;
use App\Domain\Auth\UserGateway;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AccountLinkerTest extends TestCase
{
    public function testFirstLoginAdoptsTheBrowsersCurrentVisitorId(): void
    {
        $gateway = new InMemoryUserGateway();
        $linker = new AccountLinker($gateway);

        $user = $linker->resolveUser('new@example.com', 'Ada', 'Lovelace', 'existing-anon-visitor-id');

        self::assertSame('existing-anon-visitor-id', $user->getVisitorId());
        self::assertSame('new@example.com', $user->getEmail());
        self::assertCount(1, $gateway->saved);
    }

    public function testReturningUserKeepsItsOwnStoredVisitorIdUnchanged(): void
    {
        $gateway = new InMemoryUserGateway();
        $existing = new User('returning@example.com', 'Grace', 'Hopper', 'original-visitor-id');
        $gateway->users[] = $existing;

        $linker = new AccountLinker($gateway);
        $user = $linker->resolveUser('returning@example.com', 'Grace', 'Hopper', 'a-different-browsers-visitor-id');

        self::assertSame($existing, $user);
        self::assertSame('original-visitor-id', $user->getVisitorId());
        self::assertCount(0, $gateway->saved);
    }

    public function testNewUserGetsAFreshVisitorIdWhenTheCurrentOneIsAlreadyClaimed(): void
    {
        $gateway = new InMemoryUserGateway();
        $gateway->users[] = new User('someone-else@example.com', 'Someone', 'Else', 'already-claimed-visitor-id');

        $linker = new AccountLinker($gateway);
        $user = $linker->resolveUser('new@example.com', 'Ada', 'Lovelace', 'already-claimed-visitor-id');

        self::assertNotSame('already-claimed-visitor-id', $user->getVisitorId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $user->getVisitorId());
    }
}

/**
 * @internal minimal in-memory fake, not a mock framework - keeps this test
 * pure PHP with no database, matching the project's existing test style.
 */
final class InMemoryUserGateway implements UserGateway
{
    /** @var User[] */
    public array $users = [];

    /** @var User[] */
    public array $saved = [];

    public function findOneByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getEmail() === $email) {
                return $user;
            }
        }

        return null;
    }

    public function findOneByVisitorId(string $visitorId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getVisitorId() === $visitorId) {
                return $user;
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $this->users[] = $user;
        $this->saved[] = $user;
    }
}
