<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-browser preferences (theme, hints) plus the account block - open to
 * anonymous visitors too, since both preferences live in localStorage, not
 * on an account.
 */
final class ProfileController extends AbstractController
{
    #[Route('/profil', name: 'profile_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig');
    }
}
