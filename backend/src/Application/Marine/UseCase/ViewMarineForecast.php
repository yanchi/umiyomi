<?php

declare(strict_types=1);

namespace App\Application\Marine\UseCase;

use App\Application\Marine\DTO\GroupAvailability;
use App\Application\Marine\DTO\HourlyForecastView;
use App\Application\Marine\DTO\MarineForecastResult;
use App\Application\Marine\DTO\MarineForecastView;
use App\Application\Marine\Port\Clock;
use App\Application\Marine\Port\MarineForecastCache;
use App\Application\Marine\Port\MarineForecastProvider;
use App\Domain\Marine\Availability;
use App\Domain\Marine\CompassPoint;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\ForecastPeriod;
use App\Domain\Marine\ForecastTimeline;
use App\Domain\Marine\HourlyForecast;
use App\Domain\Marine\MarineForecast;

final readonly class ViewMarineForecast
{
    private const string DISPLAY_TIME_ZONE = 'Asia/Tokyo';

    // 予報は最大 1 時間再利用するため、その間に現在時刻が進んでも 72 時間先の列まで埋まるよう 1 時間多く取得する
    private const int FETCH_HOURS = 73;

    public function __construct(
        private MarineForecastProvider $provider,
        private MarineForecastCache $cache,
        private Clock $clock,
    ) {
    }

    public function execute(ViewMarineForecastInput $input): MarineForecastResult
    {
        $now = $this->clock->now();
        $coordinate = new Coordinate($input->latitude, $input->longitude);

        $cached = $this->cache->find($coordinate);
        if (null !== $cached && $cached->isReusableAt($now)) {
            return MarineForecastResult::fresh($this->toView($cached, $now));
        }

        $fetched = $this->provider->forecast($coordinate, ForecastPeriod::startingAt($now, self::FETCH_HOURS));
        if ($fetched->isComplete()) {
            $this->cache->save($fetched);
        }

        return MarineForecastResult::fresh($this->toView($fetched, $now));
    }

    private function toView(MarineForecast $forecast, \DateTimeImmutable $now): MarineForecastView
    {
        $timeZone = new \DateTimeZone(self::DISPLAY_TIME_ZONE);

        return new MarineForecastView(
            latitude: $forecast->coordinate->latitude(),
            longitude: $forecast->coordinate->longitude(),
            fetchedAt: $forecast->fetchedAt->setTimezone($timeZone),
            nextRefetchAt: $forecast->nextRefetchAt()->setTimezone($timeZone),
            wind: self::groupAvailability($forecast->wind),
            sea: self::groupAvailability($forecast->sea),
            hours: array_map(
                static fn (HourlyForecast $hour): HourlyForecastView => new HourlyForecastView(
                    time: $hour->time->setTimezone($timeZone),
                    windSpeed: $hour->windSpeed,
                    windGust: $hour->windGust,
                    windDirection: self::compassPoint($hour->windDirection),
                    waveHeight: $hour->waveHeight,
                    waveDirection: self::compassPoint($hour->waveDirection),
                    wavePeriod: $hour->wavePeriod,
                    swellHeight: $hour->swellHeight,
                    swellDirection: self::compassPoint($hour->swellDirection),
                    swellPeriod: $hour->swellPeriod,
                ),
                ForecastTimeline::select($forecast, $now, $timeZone),
            ),
        );
    }

    private static function groupAvailability(Availability $availability): GroupAvailability
    {
        return match ($availability) {
            Availability::Available => GroupAvailability::Available,
            Availability::NotProvidedAtLocation => GroupAvailability::NotProvidedAtLocation,
            Availability::FetchFailed => GroupAvailability::FetchFailed,
        };
    }

    private static function compassPoint(?float $degrees): ?string
    {
        return null === $degrees ? null : CompassPoint::fromDegrees($degrees)->value;
    }
}
