<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

final readonly class HourlyForecastView
{
    /**
     * @param \DateTimeImmutable $time           日本時間
     * @param float|null         $windSpeed      m/s
     * @param float|null         $windGust       m/s
     * @param string|null        $windDirection  16 方位の識別子（NNE など）
     * @param float|null         $waveHeight     m
     * @param string|null        $waveDirection  16 方位の識別子
     * @param float|null         $wavePeriod     秒
     * @param float|null         $swellHeight    m
     * @param string|null        $swellDirection 16 方位の識別子
     * @param float|null         $swellPeriod    秒
     */
    public function __construct(
        public \DateTimeImmutable $time,
        public ?float $windSpeed,
        public ?float $windGust,
        public ?string $windDirection,
        public ?float $waveHeight,
        public ?string $waveDirection,
        public ?float $wavePeriod,
        public ?float $swellHeight,
        public ?string $swellDirection,
        public ?float $swellPeriod,
    ) {
    }
}
