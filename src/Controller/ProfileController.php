<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-browser preferences (theme, hints) plus the account block - open to
 * anonymous visitors too, since those preferences live in localStorage, not
 * on an account. Mail preferences are the one exception - they only mean
 * anything for a logged-in account (anonymous visitors have no address to
 * mail), so that section only renders/submits when app.user is set.
 */
final class ProfileController extends AbstractController
{
    private const CSRF_INTENTION = 'profile';

    #[Route('/profil', name: 'profile_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'csrfIntention' => self::CSRF_INTENTION,
        ]);
    }

    /**
     * One checkbox today (streak reminders), but deliberately modeled as a
     * set of mail-preference fields on User (streakReminderOptOut, ...) so
     * a future second mail type is just another column + checkbox row, not
     * a restructure.
     */
    #[Route('/profil/mail-einstellungen', name: 'profile_mail_preferences', methods: ['POST'])]
    public function updateMailPreferences(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertValidCsrf($request);
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            if ($request->request->getBoolean('streak_reminders')) {
                $user->optInToStreakReminders();
            } else {
                $user->optOutOfStreakReminders();
            }
            $em->flush();
        }

        return $this->redirectToRoute('profile_index');
    }

    private function assertValidCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
