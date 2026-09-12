<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Entity\User;

/**
 * Decides what "visitorId" a request should actually see, once both the raw
 * cookie value and the current auth state are known. Pure PHP so it's
 * testable the same way AccountLinker is - no HTTP/DI types.
 *
 * A visitorId claimed by a User account must only ever resolve to real data
 * while authenticated AS that user - matching purely by cookie would let a
 * logged-out visit on the same browser keep seeing the account's history,
 * which is confusing about whether you're actually logged in or not.
 */
final class VisitorIdentityResolver
{
    public function resolve(?User $user, string $cookieVisitorId, bool $claimedByOther): string
    {
        if ($user !== null) {
            return $user->getVisitorId();
        }

        if ($claimedByOther) {
            return '';
        }

        return $cookieVisitorId;
    }
}
