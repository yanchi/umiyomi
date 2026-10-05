<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use App\Presentation\Web\Feedback\FeedbackFormLink;
use App\Presentation\Web\Input\FeedbackContextParser;
use App\Presentation\Web\ViewModel\FeedbackPageViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly FeedbackFormLink $formLink,
        private readonly FeedbackContextParser $parser,
        private readonly FeedbackPageViewModelFactory $viewModelFactory,
    ) {
    }

    #[Route('/feedback', name: 'app_feedback', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // フォームが未設定のときはフッターにもリンクを出さないので、直接開かれても案内する先がない
        if (!$this->formLink->isAvailable()) {
            throw $this->createNotFoundException();
        }

        $context = $this->parser->parse(
            $request->query->getString('lat'),
            $request->query->getString('lon'),
            $request->query->getString('updated'),
            $request->query->getString('input_lat'),
            $request->query->getString('input_lon'),
        );

        return $this->render('feedback/index.html.twig', [
            'page' => $this->viewModelFactory->create($context),
        ]);
    }
}
