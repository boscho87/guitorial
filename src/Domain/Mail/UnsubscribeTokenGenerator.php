<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs/verifies the one-click unsubscribe link in a streak-reminder mail -
 * no login required to act on it, so the link itself has to prove it
 * wasn't guessed or tampered with. hash_equals() guards the comparison
 * against a timing side-channel.
 */
final class UnsubscribeTokenGenerator
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function generate(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, $this->secret);
    }

    public function isValid(int $userId, string $token): bool
    {
        return hash_equals($this->generate($userId), $token);
    }
}
