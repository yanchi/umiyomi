<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

final readonly class MarineForecastView
{
    /**
     * @param float                    $latitude      小数点以下 2 桁に丸めた値
     * @param float                    $longitude     小数点以下 2 桁に丸めた値
     * @param \DateTimeImmutable       $fetchedAt     日本時間
     * @param \DateTimeImmutable       $nextRefetchAt 日本時間
     * @param list<HourlyForecastView> $hours         表示する時刻だけ
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public \DateTimeImmutable $fetchedAt,
        public \DateTimeImmutable $nextRefetchAt,
        public GroupAvailability $wind,
        public GroupAvailability $sea,
        public array $hours,
    ) {
    }
}
