<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * public/ の静的ファイルにせずルートにするのは、サイトマップのアドレスを DEFAULT_URI から作り、
 * 環境ごとに正しいアドレスを返すため。
 * 登録対象はトップだけなので、このファイル自体は検索結果に載せない（X-Robots-Tag: noindex）。
 */
final class RobotsTxtController extends AbstractController
{
    #[Route('/robots.txt', name: 'app_robots_txt', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('seo/robots.txt.twig', response: new Response(headers: [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]));
    }
}
