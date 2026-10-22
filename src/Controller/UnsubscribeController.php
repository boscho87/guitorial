<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mail\UnsubscribeTokenGenerator;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one-click unsubscribe link in a streak-reminder mail - deliberately
 * works without being logged in (that's the whole point of a one click
 * link), so the signed token is what proves the request is legitimate, not
 * a session. Always shows the same neutral confirmation regardless of
 * whether id/token actually matched anything, so this can't be used to
 * probe which user ids exist.
 */
final class UnsubscribeController extends AbstractController
{
    #[Route('/erinnerungen/abmelden/{id}/{token}', name: 'streak_reminder_unsubscribe', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, string $token, UserRepository $users, UnsubscribeTokenGenerator $tokens, EntityManagerInterface $em): Response
    {
        $user = $users->find($id);
        if ($user !== null && $tokens->isValid($id, $token)) {
            $user->optOutOfStreakReminders();
            $em->flush();
        }

        return $this->render('mail/unsubscribed.html.twig');
    }
}
