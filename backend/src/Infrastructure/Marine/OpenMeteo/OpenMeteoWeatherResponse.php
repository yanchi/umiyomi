<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

/**
 * Weather API（風）のレスポンスをそのまま写した DTO。Open-Meteo の形式を Infrastructure の外に出さないため、ここで形式だけを検証する.
 */
final readonly class OpenMeteoWeatherResponse
{
    /**
     * @param list<int>        $time             unixtime（UTC）
     * @param list<float|null> $windSpeed10m     m/s
     * @param list<float|null> $windGusts10m     m/s
     * @param list<float|null> $windDirection10m 度
     */
    public function __construct(
        public array $time,
        public array $windSpeed10m,
        public array $windGusts10m,
        public array $windDirection10m,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException 形式が想定と違う場合
     */
    public static function fromArray(array $data): self
    {
        $hourly = OpenMeteoHourly::fromArray($data);

        return new self(
            $hourly->time(),
            $hourly->values('wind_speed_10m'),
            $hourly->values('wind_gusts_10m'),
            $hourly->values('wind_direction_10m'),
        );
    }
}
