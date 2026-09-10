<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Entity\User;

/**
 * AccountLinker's only dependency - deliberately narrow (just the 2 lookups
 * it needs plus save()) so it can be unit-tested with a plain in-memory
 * fake instead of a real database, matching this project's existing
 * "pure PHP domain tests" convention. UserRepository implements this;
 * Symfony autowires the interface to it automatically since it's the only
 * implementation in src/.
 */
interface UserGateway
{
    public function findOneByEmail(string $email): ?User;

    public function findOneByVisitorId(string $visitorId): ?User;

    public function save(User $user): void;
}
