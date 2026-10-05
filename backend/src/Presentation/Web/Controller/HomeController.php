<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'form' => ['latitude' => '', 'longitude' => '', 'latitudeError' => null, 'longitudeError' => null, 'staleNotice' => null],
        ]);
    }
}
