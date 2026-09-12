<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\Auth\UserGateway;
use App\Domain\Session\VisitorIdentityResolver;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reconciles the "visitorId" request attribute (set from the raw cookie by
 * VisitorCookieSubscriber) against the current auth state, so a browser
 * whose cookie happens to match a User's claimed visitorId only ever sees
 * that account's data while actually authenticated as them - not just
 * because the cookie matches.
 *
 * Deliberately on KernelEvents::CONTROLLER, not KernelEvents::REQUEST:
 * Security::getUser() depends on the firewall's lazy-auth initializer,
 * which (for a `lazy: true` firewall) is itself registered by
 * Symfony\Bundle\SecurityBundle\EventListener\FirewallListener on
 * kernel.request at priority 8. VisitorCookieSubscriber's own
 * kernel.request listener runs at priority 30 - i.e. before that -  so
 * calling getUser() at that point would always see "no user", even for a
 * genuinely logged-in visitor, and would incorrectly blank out their own
 * data. kernel.controller always fires strictly after the whole
 * kernel.request phase, so it isn't a numeric-priority race with another
 * bundle's internals.
 */
final class VisitorIdentityListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserGateway $users,
        private readonly VisitorIdentityResolver $resolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $cookieVisitorId = $request->attributes->get('visitorId');
        if (!is_string($cookieVisitorId)) {
            return;
        }

        $user = $this->security->getUser();
        $claimedByOther = $user === null && $this->users->findOneByVisitorId($cookieVisitorId) !== null;

        $request->attributes->set(
            'visitorId',
            $this->resolver->resolve($user instanceof User ? $user : null, $cookieVisitorId, $claimedByOther),
        );
    }
}
