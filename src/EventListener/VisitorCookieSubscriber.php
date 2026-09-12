<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\Session\VisitorId;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Anonymous per-visitor identity, ahead of real accounts: a long-lived
 * cookie holding a random id, used to scope practice-session history.
 * Read (or minted) as early as possible so every controller can rely on
 * the "visitorId" request attribute being set; re-issued on the response
 * only when it didn't already exist, so the id never changes.
 *
 * This class only ever deals with the raw/minted cookie value - it has no
 * opinion on auth state. VisitorIdentityListener runs afterward (on
 * kernel.controller, once the security context is actually available) and
 * may overwrite the "visitorId" attribute this sets, e.g. blanking it when
 * the cookie is claimed by an account the current request isn't
 * authenticated as.
 */
final class VisitorCookieSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'guitorial_visitor';
    private const REQUEST_ATTRIBUTE = 'visitorId';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $visitorId = $request->cookies->get(self::COOKIE_NAME);

        if (!is_string($visitorId) || !VisitorId::isValid($visitorId)) {
            $visitorId = VisitorId::generate();
            $request->attributes->set('visitorIdIsNew', true);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $visitorId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->get('visitorIdIsNew') !== true) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($request->attributes->get(self::REQUEST_ATTRIBUTE))
                ->withExpires(new \DateTimeImmutable('+1 year'))
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }
}
