<?php

declare(strict_types=1);

namespace App\Tests\Domain\Session;

use App\Domain\Session\VisitorIdentityResolver;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class VisitorIdentityResolverTest extends TestCase
{
    public function testAuthenticatedUserAlwaysResolvesToTheirOwnClaimedVisitorId(): void
    {
        $user = new User('ada@example.com', 'Ada', 'Lovelace', 'user-own-visitor-id');
        $resolver = new VisitorIdentityResolver();

        $resolved = $resolver->resolve($user, 'some-other-cookie-value', false);

        self::assertSame('user-own-visitor-id', $resolved);
    }

    public function testAuthenticatedUserResolvesToOwnVisitorIdEvenIfCookieHappensToMatchIt(): void
    {
        $user = new User('ada@example.com', 'Ada', 'Lovelace', 'user-own-visitor-id');
        $resolver = new VisitorIdentityResolver();

        $resolved = $resolver->resolve($user, 'user-own-visitor-id', true);

        self::assertSame('user-own-visitor-id', $resolved);
    }

    public function testLoggedOutWithACookieClaimedBySomeoneElseResolvesToEmpty(): void
    {
        $resolver = new VisitorIdentityResolver();

        $resolved = $resolver->resolve(null, 'claimed-visitor-id', true);

        self::assertSame('', $resolved);
    }

    public function testLoggedOutWithATrulyAnonymousCookieResolvesToTheCookie(): void
    {
        $resolver = new VisitorIdentityResolver();

        $resolved = $resolver->resolve(null, 'never-claimed-visitor-id', false);

        self::assertSame('never-claimed-visitor-id', $resolved);
    }
}
