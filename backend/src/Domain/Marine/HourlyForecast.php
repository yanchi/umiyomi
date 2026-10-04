<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 風速・波高などは検証・変換のロジックを持たないため VO にせず、単位はフィールド名と docblock で表す.
 */
final readonly class HourlyForecast
{
    /**
     * @param \DateTimeImmutable $time           予報の対象時刻（正時）
     * @param float|null         $windSpeed      10m 平均風速 m/s
     * @param float|null         $windGust       突風 m/s
     * @param float|null         $windDirection  風が来る方向（度）
     * @param float|null         $waveHeight     波高 m
     * @param float|null         $waveDirection  波が来る方向（度）
     * @param float|null         $wavePeriod     波周期 秒
     * @param float|null         $swellHeight    うねりの高さ m
     * @param float|null         $swellDirection うねりが来る方向（度）
     * @param float|null         $swellPeriod    うねりの周期 秒
     */
    public function __construct(
        public \DateTimeImmutable $time,
        public ?float $windSpeed,
        public ?float $windGust,
        public ?float $windDirection,
        public ?float $waveHeight,
        public ?float $waveDirection,
        public ?float $wavePeriod,
        public ?float $swellHeight,
        public ?float $swellDirection,
        public ?float $swellPeriod,
    ) {
    }

    public function hasWindValue(): bool
    {
        return null !== $this->windSpeed || null !== $this->windGust || null !== $this->windDirection;
    }

    public function hasSeaValue(): bool
    {
        return null !== $this->waveHeight || null !== $this->waveDirection || null !== $this->wavePeriod
            || null !== $this->swellHeight || null !== $this->swellDirection || null !== $this->swellPeriod;
    }
}
