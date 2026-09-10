<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Session\VisitorId;
use App\Entity\User;

/**
 * Find-or-create for the User behind an OAuth login. Deliberately does NOT
 * merge/reassign any existing data: a returning User (matched by email)
 * keeps whatever visitorId it claimed at its first-ever login, unchanged.
 * Only a brand-new User adopts the browser's current anonymous visitorId -
 * the one case where doing so is both safe (nothing else could already be
 * using it as an account) and exactly what "log in to keep your anonymous
 * progress" means. See the planning doc for the accepted trade-off this
 * implies for logins from a second/new browser.
 */
final class AccountLinker
{
    public function __construct(private readonly UserGateway $users)
    {
    }

    public function resolveUser(string $email, string $firstName, string $lastName, string $currentVisitorId): User
    {
        $existing = $this->users->findOneByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        $visitorId = $this->users->findOneByVisitorId($currentVisitorId) === null
            ? $currentVisitorId
            : VisitorId::generate();

        $user = new User($email, $firstName, $lastName, $visitorId);
        $this->users->save($user);

        return $user;
    }
}
