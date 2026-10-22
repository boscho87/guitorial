<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Export\AccountDataExporter;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DSGVO/GDPR Art. 15/20 - a complete, structured, machine-readable dump of
 * everything this app holds for the logged-in account. Login-only: an
 * anonymous visitorId has no account to attach the download to, and
 * nothing here needs a CSRF token (GET, no state change).
 */
final class DataExportController extends AbstractController
{
    #[Route('/profil/export', name: 'profile_data_export', methods: ['GET'])]
    public function __invoke(AccountDataExporter $exporter): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('profile_index');
        }

        $response = new JsonResponse($exporter->export($user), 200, [], false);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            sprintf('guitorial-export-%s.json', (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }
}
