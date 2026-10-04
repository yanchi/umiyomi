<?php

declare(strict_types=1);

namespace App\Application\Marine\Port;

use App\Domain\Marine\Coordinate;
use App\Domain\Marine\FetchFailure;
use App\Domain\Marine\ForecastPeriod;
use App\Domain\Marine\MarineForecast;

interface MarineForecastProvider
{
    /**
     * 片方のグループだけ取得できなかった場合は、そのグループを FetchFailed にした予報を返す.
     *
     * @throws FetchFailure どのグループも取得できなかった場合
     */
    public function forecast(Coordinate $coordinate, ForecastPeriod $period): MarineForecast;
}
