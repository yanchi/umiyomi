<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

final readonly class MarineForecastResult
{
    /**
     * @param MarineForecastView|null $forecast Fresh / Stale のときだけ値がある
     */
    private function __construct(
        public ForecastStatus $status,
        public ?MarineForecastView $forecast,
    ) {
    }

    public static function fresh(MarineForecastView $forecast): self
    {
        return new self(ForecastStatus::Fresh, $forecast);
    }

    public static function stale(MarineForecastView $forecast): self
    {
        return new self(ForecastStatus::Stale, $forecast);
    }

    public static function unavailable(): self
    {
        return new self(ForecastStatus::Unavailable, null);
    }

    public static function rateLimited(): self
    {
        return new self(ForecastStatus::RateLimited, null);
    }
}
