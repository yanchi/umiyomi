<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Marine;

use App\Application\Marine\DTO\ForecastStatus;
use App\Application\Marine\DTO\GroupAvailability;
use App\Application\Marine\DTO\MarineForecastResult;
use App\Application\Marine\DTO\MarineForecastView;
use App\Application\Marine\UseCase\ViewMarineForecast;
use App\Application\Marine\UseCase\ViewMarineForecastInput;
use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\HourlyForecast;
use App\Infrastructure\Marine\Cache\SymfonyMarineForecastCache;
use App\Tests\Support\FakeMarineForecastProvider;
use App\Tests\Support\FixedClock;
use App\Tests\Support\MarineForecastBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ViewMarineForecastTest extends TestCase
{
    private FixedClock $clock;
    private FakeMarineForecastProvider $provider;
    private SymfonyMarineForecastCache $cache;
    private ViewMarineForecast $useCase;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-05T11:15:00Z');
        $this->provider = new FakeMarineForecastProvider($this->clock);
        $this->cache = new SymfonyMarineForecastCache(new ArrayAdapter());
        $this->useCase = new ViewMarineForecast($this->provider, $this->cache, $this->clock);
    }

    public function testFetchesUnknownLocationAndCachesIt(): void
    {
        $result = $this->view();

        self::assertSame(ForecastStatus::Fresh, $result->status);
        self::assertSame(1, $this->provider->callCount());
        self::assertNotNull($this->cache->find(new Coordinate(27.75, 129.05)));

        $forecast = $this->forecastOf($result);
        self::assertSame(27.75, $forecast->latitude);
        self::assertSame(129.05, $forecast->longitude);
        self::assertSame('2026-10-05T20:15:00+09:00', $forecast->fetchedAt->format(\DATE_ATOM));
        self::assertSame('2026-10-05T21:15:00+09:00', $forecast->nextRefetchAt->format(\DATE_ATOM));
        self::assertSame(GroupAvailability::Available, $forecast->wind);
        self::assertSame(GroupAvailability::Available, $forecast->sea);
    }

    public function testRoundsInputCoordinate(): void
    {
        $forecast = $this->forecastOf($this->view(27.754, 129.0461));

        self::assertSame(27.75, $forecast->latitude);
        self::assertSame(129.05, $forecast->longitude);
    }

    public function testReusesForecastWithinOneHour(): void
    {
        $this->view();
        $this->clock->advance('+59 minutes');

        $forecast = $this->forecastOf($this->view());

        self::assertSame(1, $this->provider->callCount());
        self::assertSame('2026-10-05T20:15:00+09:00', $forecast->fetchedAt->format(\DATE_ATOM));
    }

    public function testRefetchesAfterOneHour(): void
    {
        $this->view();
        $this->clock->advance('+60 minutes');

        $forecast = $this->forecastOf($this->view());

        self::assertSame(2, $this->provider->callCount());
        self::assertSame('2026-10-05T21:15:00+09:00', $forecast->fetchedAt->format(\DATE_ATOM));
    }

    public function testReturnsSelectedHoursInJapanTime(): void
    {
        $hours = $this->forecastOf($this->view())->hours;

        self::assertCount(40, $hours);
        self::assertSame('2026-10-05T20:00:00+09:00', $hours[0]->time->format(\DATE_ATOM));
        self::assertSame('2026-10-06T21:00:00+09:00', $hours[24]->time->format(\DATE_ATOM));
        self::assertSame('2026-10-08T18:00:00+09:00', $hours[39]->time->format(\DATE_ATOM));
    }

    public function testConvertsValuesAndDirections(): void
    {
        $hour = $this->forecastOf($this->view())->hours[0];

        self::assertSame(MarineForecastBuilder::WIND_SPEED, $hour->windSpeed);
        self::assertSame(MarineForecastBuilder::WIND_GUST, $hour->windGust);
        self::assertSame('NNE', $hour->windDirection);
        self::assertSame(MarineForecastBuilder::WAVE_HEIGHT, $hour->waveHeight);
        self::assertSame('E', $hour->waveDirection);
        self::assertSame(MarineForecastBuilder::WAVE_PERIOD, $hour->wavePeriod);
        self::assertSame(MarineForecastBuilder::SWELL_HEIGHT, $hour->swellHeight);
        self::assertSame('SE', $hour->swellDirection);
        self::assertSame(MarineForecastBuilder::SWELL_PERIOD, $hour->swellPeriod);
    }

    public function testMissingDirectionStaysNull(): void
    {
        $this->cache->save(MarineForecastBuilder::create()->withHours([
            new HourlyForecast(new \DateTimeImmutable('2026-10-05T11:00:00Z'), 5.0, null, null, 1.0, null, null, null, null, null),
        ])->build());

        $hour = $this->forecastOf($this->view())->hours[0];

        self::assertSame(0, $this->provider->callCount());
        self::assertNull($hour->windDirection);
        self::assertNull($hour->waveDirection);
        self::assertNull($hour->swellDirection);
        self::assertNull($hour->windGust);
    }

    public function testMapsGroupAvailability(): void
    {
        $this->provider->willReturnSeaAvailability(Availability::NotProvidedAtLocation);

        $forecast = $this->forecastOf($this->view());

        self::assertSame(GroupAvailability::Available, $forecast->wind);
        self::assertSame(GroupAvailability::NotProvidedAtLocation, $forecast->sea);
        self::assertNull($forecast->hours[0]->waveHeight);
    }

    private function view(float $latitude = 27.75, float $longitude = 129.05, string $clientKey = '192.0.2.1'): MarineForecastResult
    {
        return $this->useCase->execute(new ViewMarineForecastInput($latitude, $longitude, $clientKey));
    }

    private function forecastOf(MarineForecastResult $result): MarineForecastView
    {
        self::assertNotNull($result->forecast);

        return $result->forecast;
    }
}
