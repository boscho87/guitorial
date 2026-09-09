<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PracticeGuideController extends AbstractController
{
    #[Route('/richtig-ueben', name: 'practice_guide_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('practice_guide/index.html.twig');
    }
}
