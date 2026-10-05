<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * robots.txt と同じく、アドレスを DEFAULT_URI から作るためルートにする。
 * 登録対象はトップだけなので、サイトマップ自体は検索結果に載せない（X-Robots-Tag: noindex）。
 */
final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('seo/sitemap.xml.twig', response: new Response(headers: [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]));
    }
}
