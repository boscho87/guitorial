<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Only the public, non-personalized pages belong here - never a diary/
 * history detail, session, or POST-only action route, since those are
 * either per-visitor (nothing meaningful for a search engine to index) or
 * not real pages at all.
 */
final class SitemapController extends AbstractController
{
    private const URLS = [
        ['route' => 'home_index', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['route' => 'training_index', 'changefreq' => 'weekly', 'priority' => '0.8'],
        ['route' => 'key_recognition_settings', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'capo_trainer_settings', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'harmonic_function_settings', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'fretboard_settings', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'scale_generator_settings', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'practice_guide_index', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['route' => 'diary_index', 'changefreq' => 'weekly', 'priority' => '0.5'],
        ['route' => 'routine_index', 'changefreq' => 'weekly', 'priority' => '0.5'],
        ['route' => 'history_index', 'changefreq' => 'weekly', 'priority' => '0.5'],
        ['route' => 'progress_index', 'changefreq' => 'weekly', 'priority' => '0.5'],
        ['route' => 'app_login', 'changefreq' => 'yearly', 'priority' => '0.3'],
        ['route' => 'privacy_index', 'changefreq' => 'yearly', 'priority' => '0.2'],
        ['route' => 'imprint_index', 'changefreq' => 'yearly', 'priority' => '0.2'],
        ['route' => 'terms_index', 'changefreq' => 'yearly', 'priority' => '0.2'],
    ];

    #[Route('/sitemap.xml', name: 'sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('sitemap/index.xml.twig', ['urls' => self::URLS]);
        $response->headers->set('Content-Type', 'application/xml');

        return $response;
    }
}
