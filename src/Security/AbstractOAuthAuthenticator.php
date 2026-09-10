<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Auth\AccountLinker;
use App\Domain\Session\VisitorId;
use App\Entity\User;
use App\EventListener\VisitorCookieSubscriber;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Shared OAuth login flow for every provider - each concrete authenticator
 * only supplies the client key, its own check-route name, and how to read
 * email/first/last name off that provider's resource owner. The actual
 * account resolution (find-or-create, visitorId claiming) is entirely
 * AccountLinker's job, not this class's - this class is just the OAuth
 * plumbing.
 */
abstract class AbstractOAuthAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly AccountLinker $accountLinker,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    abstract protected function clientKey(): string;

    abstract protected function checkRouteName(): string;

    /**
     * @return array{email: string, firstName: string, lastName: string}|null null if the provider didn't supply a usable email
     */
    abstract protected function extractProfile(ResourceOwnerInterface $resourceOwner): ?array;

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === $this->checkRouteName();
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient($this->clientKey());
        $accessToken = $this->fetchAccessToken($client);
        $currentVisitorId = $this->currentVisitorId($request);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client, $currentVisitorId): User {
                $profile = $this->extractProfile($client->fetchUserFromToken($accessToken));
                if ($profile === null || $profile['email'] === '') {
                    throw new CustomUserMessageAuthenticationException('security.login.no_email');
                }

                return $this->accountLinker->resolveUser(
                    $profile['email'],
                    $profile['firstName'],
                    $profile['lastName'],
                    $currentVisitorId,
                );
            }),
            [new RememberMeBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $response = new RedirectResponse($this->router->generate('diary_index'));

        $user = $token->getUser();
        if ($user instanceof User && $request->cookies->get(VisitorCookieSubscriber::COOKIE_NAME) !== $user->getVisitorId()) {
            $response->headers->setCookie(
                Cookie::create(VisitorCookieSubscriber::COOKIE_NAME)
                    ->withValue($user->getVisitorId())
                    ->withExpires(new \DateTimeImmutable('+1 year'))
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX),
            );
        }

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $messageKey = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessage()
            : 'security.login.failed';
        $request->getSession()->getFlashBag()->add('error', $messageKey);

        return new RedirectResponse($this->router->generate('app_login'));
    }

    /**
     * Reads the raw cookie directly rather than the "visitorId" request
     * attribute VisitorCookieSubscriber sets - avoids any dependency on
     * that listener's priority having already run before this one.
     */
    private function currentVisitorId(Request $request): string
    {
        $cookieValue = $request->cookies->get(VisitorCookieSubscriber::COOKIE_NAME);

        return is_string($cookieValue) && VisitorId::isValid($cookieValue) ? $cookieValue : VisitorId::generate();
    }
}
