<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\HourlyForecast;
use App\Domain\Marine\MarineForecast;

final class MarineForecastBuilder
{
    public const float WIND_SPEED = 5.2;
    public const float WIND_GUST = 8.4;
    public const float WIND_DIRECTION = 22.5;
    public const float WAVE_HEIGHT = 1.25;
    public const float WAVE_DIRECTION = 90.0;
    public const float WAVE_PERIOD = 6.3;
    public const float SWELL_HEIGHT = 0.8;
    public const float SWELL_DIRECTION = 135.0;
    public const float SWELL_PERIOD = 9.1;

    private Coordinate $coordinate;
    private \DateTimeImmutable $fetchedAt;
    private \DateTimeImmutable $from;
    private int $hourCount = 73;
    private Availability $wind = Availability::Available;
    private Availability $sea = Availability::Available;

    /** @var list<HourlyForecast>|null */
    private ?array $hours = null;

    private function __construct()
    {
        $this->coordinate = new Coordinate(27.75, 129.05);
        $this->fetchedAt = new \DateTimeImmutable('2026-10-05T11:15:00Z');
        $this->from = new \DateTimeImmutable('2026-10-05T11:00:00Z');
    }

    public static function create(): self
    {
        return new self();
    }

    public function at(Coordinate $coordinate): self
    {
        $this->coordinate = $coordinate;

        return $this;
    }

    /**
     * 取得日時を変えると、期間の開始もその時刻を正時に切り捨てた値にそろえる（Provider の実際の挙動に合わせる）.
     */
    public function fetchedAt(\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;
        $utc = $fetchedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->from = $utc->setTime((int) $utc->format('G'), 0);

        return $this;
    }

    public function hoursFrom(\DateTimeImmutable $from, int $count): self
    {
        $this->from = $from;
        $this->hourCount = $count;

        return $this;
    }

    public function wind(Availability $wind): self
    {
        $this->wind = $wind;

        return $this;
    }

    public function sea(Availability $sea): self
    {
        $this->sea = $sea;

        return $this;
    }

    /**
     * @param list<HourlyForecast> $hours
     */
    public function withHours(array $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    public function build(): MarineForecast
    {
        return new MarineForecast($this->coordinate, $this->fetchedAt, $this->wind, $this->sea, $this->hours ?? $this->buildHours());
    }

    /**
     * @return list<HourlyForecast>
     */
    private function buildHours(): array
    {
        $windAvailable = Availability::Available === $this->wind;
        $seaAvailable = Availability::Available === $this->sea;
        $hours = [];
        for ($i = 0; $i < $this->hourCount; ++$i) {
            $hours[] = new HourlyForecast(
                time: $this->from->modify(\sprintf('+%d hours', $i)),
                windSpeed: $windAvailable ? self::WIND_SPEED : null,
                windGust: $windAvailable ? self::WIND_GUST : null,
                windDirection: $windAvailable ? self::WIND_DIRECTION : null,
                waveHeight: $seaAvailable ? self::WAVE_HEIGHT : null,
                waveDirection: $seaAvailable ? self::WAVE_DIRECTION : null,
                wavePeriod: $seaAvailable ? self::WAVE_PERIOD : null,
                swellHeight: $seaAvailable ? self::SWELL_HEIGHT : null,
                swellDirection: $seaAvailable ? self::SWELL_DIRECTION : null,
                swellPeriod: $seaAvailable ? self::SWELL_PERIOD : null,
            );
        }

        return $hours;
    }
}
