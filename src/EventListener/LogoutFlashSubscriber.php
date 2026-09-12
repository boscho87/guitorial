<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Confirms an explicit logout with a flash, so it's obvious it happened
 * rather than something to notice only later (e.g. by seeing "Anmelden" in
 * the header, or worse, by history looking empty - see
 * VisitorIdentityListener). Priority -100 is required, not decorative:
 * Symfony's own SessionLogoutListener hangs off the same event at the
 * default priority 0 and calls $session->invalidate() there - two
 * priority-0 listeners have no guaranteed order relative to each other, so
 * without an explicit lower priority this flash could be added before the
 * session is invalidated and never survive into the post-logout redirect.
 */
final class LogoutFlashSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => ['onLogout', -100],
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add('info', 'security.logout.flash');
    }
}
