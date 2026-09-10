<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route('/datenschutz', name: 'privacy_index', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route('/impressum', name: 'imprint_index', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->render('legal/imprint.html.twig');
    }

    #[Route('/nutzungsbestimmung', name: 'terms_index', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }
}
