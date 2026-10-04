<?php

declare(strict_types=1);

namespace App\Application\Marine\Port;

use App\Domain\Marine\Coordinate;
use App\Domain\Marine\MarineForecast;

interface MarineForecastCache
{
    /**
     * 期限（1 時間）を過ぎた予報も返す。取得失敗時の代替表示に使えるかは呼び出し側が取得日時から判定する.
     */
    public function find(Coordinate $coordinate): ?MarineForecast;

    public function save(MarineForecast $forecast): void;
}
