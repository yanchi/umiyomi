<?php

declare(strict_types=1);

namespace App\Presentation\Web\Controller;

use App\Application\Marine\DTO\ForecastStatus;
use App\Application\Marine\UseCase\ViewMarineForecast;
use App\Application\Marine\UseCase\ViewMarineForecastInput;
use App\Presentation\Web\Input\CoordinateQueryParser;
use App\Presentation\Web\ViewModel\ForecastPageViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ForecastController extends AbstractController
{
    public function __construct(
        private readonly CoordinateQueryParser $parser,
        private readonly ViewMarineForecast $viewMarineForecast,
        private readonly ForecastPageViewModelFactory $viewModelFactory,
    ) {
    }

    #[Route('/forecast', name: 'app_forecast', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $query = $this->parser->parse($request->query->getString('lat'), $request->query->getString('lon'));
        if (!$query->isValid()) {
            return $this->render('forecast/index.html.twig', [
                'page' => $this->viewModelFactory->createForInvalidInput($query),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $result = $this->viewMarineForecast->execute(
            new ViewMarineForecastInput($query->latitude, $query->longitude, $request->getClientIp() ?? 'unknown'),
        );

        // 200 以外でもフォーム付きの HTML を返す。ステータスは Functional Test での判定とアクセスログでの集計のために分ける
        // 429 に Retry-After を付けないのは、sliding window では正確な秒数を出しにくく、画面の文言で足りるため
        $status = match ($result->status) {
            ForecastStatus::Fresh, ForecastStatus::Stale => Response::HTTP_OK,
            ForecastStatus::Unavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            ForecastStatus::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
        };

        return $this->render('forecast/index.html.twig', [
            'page' => $this->viewModelFactory->create($query, $result),
        ], new Response(status: $status));
    }
}
