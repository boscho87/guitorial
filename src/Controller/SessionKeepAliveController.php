<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Touches the PHP session so a tab left open and idle (reading, typing a
 * long note, ...) doesn't silently lose it to the host's session garbage
 * collection - session.gc_probability is 0 here, so cleanup isn't the usual
 * per-request lottery but an out-of-band sweep keyed purely off each
 * session file's mtime, and session.lazy_write means merely *starting* the
 * session without writing a changed value never bumps that mtime. Once the
 * session is gone, the CSRF token already baked into any open form's
 * _token field no longer matches the (new, empty) session's - see
 * error401.html.twig. Pinged from session_keepalive_controller.js, attached
 * globally on <body> in base.html.twig, well inside session.gc_maxlifetime.
 */
#[Route('/api')]
final class SessionKeepAliveController extends AbstractController
{
    #[Route('/session-keepalive', name: 'session_keepalive', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $request->getSession()->set('lastSeenAt', time());

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
