<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every route here is either a pure redirect into the OAuth flow or a
 * pass-through the corresponding authenticator intercepts before the
 * controller body ever runs (connect/{provider}/check, logout) - see
 * AbstractOAuthAuthenticator and security.yaml.
 */
final class SecurityController extends AbstractController
{
    private const SCOPES = [
        'google' => ['email', 'profile'],
        'azure' => ['openid', 'profile', 'email'],
    ];

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('diary_index');
        }

        return $this->render('security/login.html.twig');
    }

    #[Route('/connect/{provider}', name: 'connect_start', requirements: ['provider' => 'google|azure'], methods: ['GET'])]
    public function connect(string $provider, ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient($provider)->redirect(self::SCOPES[$provider], []);
    }

    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function connectGoogleCheck(): never
    {
        throw new \LogicException('This should never be reached - GoogleAuthenticator intercepts this route first.');
    }

    #[Route('/connect/azure/check', name: 'connect_azure_check', methods: ['GET'])]
    public function connectAzureCheck(): never
    {
        throw new \LogicException('This should never be reached - AzureAuthenticator intercepts this route first.');
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This should never be reached - the security firewall intercepts logout first.');
    }
}
