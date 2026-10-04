<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

/**
 * Marine API（波・うねり）のレスポンスをそのまま写した DTO.
 */
final readonly class OpenMeteoMarineResponse
{
    /**
     * @param list<int>        $time               unixtime（UTC）
     * @param list<float|null> $waveHeight         m
     * @param list<float|null> $waveDirection      度
     * @param list<float|null> $wavePeriod         秒
     * @param list<float|null> $swellWaveHeight    m
     * @param list<float|null> $swellWaveDirection 度
     * @param list<float|null> $swellWavePeriod    秒
     */
    public function __construct(
        public array $time,
        public array $waveHeight,
        public array $waveDirection,
        public array $wavePeriod,
        public array $swellWaveHeight,
        public array $swellWaveDirection,
        public array $swellWavePeriod,
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
            $hourly->values('wave_height'),
            $hourly->values('wave_direction'),
            $hourly->values('wave_period'),
            $hourly->values('swell_wave_height'),
            $hourly->values('swell_wave_direction'),
            $hourly->values('swell_wave_period'),
        );
    }
}
