<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExternalTransmissionController extends AbstractController
{
    // Request を受け取らない。クエリを使わず、予報の取得も行わない
    #[Route('/external-transmission', name: 'app_external_transmission', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('external_transmission/index.html.twig');
    }
}
