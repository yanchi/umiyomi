<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

final readonly class MarineForecastResult
{
    /**
     * 予報がない結果（Unavailable / RateLimited）でも画面が表示中の地点を扱えるよう、座標は予報とは別に持つ.
     * 座標は Coordinate の丸め済みの値で、丸めの規則を Domain の 1 か所に保つ（Presentation が丸め直さない）.
     *
     * @param MarineForecastView|null $forecast Fresh / Stale のときだけ値がある
     */
    private function __construct(
        public ForecastStatus $status,
        public ?MarineForecastView $forecast,
        public float $latitude,
        public float $longitude,
    ) {
    }

    public static function fresh(MarineForecastView $forecast): self
    {
        return new self(ForecastStatus::Fresh, $forecast, $forecast->latitude, $forecast->longitude);
    }

    public static function stale(MarineForecastView $forecast): self
    {
        return new self(ForecastStatus::Stale, $forecast, $forecast->latitude, $forecast->longitude);
    }

    public static function unavailable(float $latitude, float $longitude): self
    {
        return new self(ForecastStatus::Unavailable, null, $latitude, $longitude);
    }

    public static function rateLimited(float $latitude, float $longitude): self
    {
        return new self(ForecastStatus::RateLimited, null, $latitude, $longitude);
    }
}
