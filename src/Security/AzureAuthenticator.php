<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

final class AzureAuthenticator extends AbstractOAuthAuthenticator
{
    protected function clientKey(): string
    {
        return 'azure';
    }

    protected function checkRouteName(): string
    {
        return 'connect_azure_check';
    }

    protected function extractProfile(ResourceOwnerInterface $resourceOwner): ?array
    {
        if (!$resourceOwner instanceof AzureResourceOwner) {
            return null;
        }

        // Personal Microsoft accounts sometimes omit the "email" claim
        // outright - upn/preferred_username reliably carry the sign-in
        // address in that case.
        $email = $resourceOwner->getEmail() ?? $resourceOwner->getUpn() ?? $resourceOwner->getPreferredUsername();
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
