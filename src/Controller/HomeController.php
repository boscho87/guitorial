<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Quiz\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home_index')]
    public function index(ToolRegistry $toolRegistry): Response
    {
        return $this->render('home/index.html.twig', [
            'tools' => $toolRegistry->all(),
        ]);
    }
}
