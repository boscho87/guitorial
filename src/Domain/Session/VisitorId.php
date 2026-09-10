<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * The one place that generates a visitorId - a random UUIDv4, used both for
 * the anonymous guitorial_visitor cookie (VisitorCookieSubscriber) and, once
 * an account logs in for the first time, as the permanent id a User claims
 * (AccountLinker). Both need byte-for-byte the same shape, so this is
 * shared rather than duplicated.
 */
final class VisitorId
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f-]{36}$/', $value);
    }
}
