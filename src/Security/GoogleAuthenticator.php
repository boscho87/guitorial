<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

final class GoogleAuthenticator extends AbstractOAuthAuthenticator
{
    protected function clientKey(): string
    {
        return 'google';
    }

    protected function checkRouteName(): string
    {
        return 'connect_google_check';
    }

    protected function extractProfile(ResourceOwnerInterface $resourceOwner): ?array
    {
        if (!$resourceOwner instanceof GoogleUser) {
            return null;
        }

        $email = $resourceOwner->getEmail();
        if ($email === null) {
            return null;
        }

        return [
            'email' => $email,
            'firstName' => $resourceOwner->getFirstName() ?? '',
            'lastName' => $resourceOwner->getLastName() ?? '',
        ];
    }
}
