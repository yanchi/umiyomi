<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\OpenMeteo;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoMarineResponse;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoResponseMapper;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoWeatherResponse;
use App\Tests\Support\OpenMeteoFixture;
use PHPUnit\Framework\TestCase;

final class OpenMeteoResponseMapperTest extends TestCase
{
    private const int T0 = 1791198000; // 2026-10-05T11:00:00Z
    private const int T1 = self::T0 + 3600;
    private const int T2 = self::T0 + 7200;

    private OpenMeteoResponseMapper $mapper;
    private Coordinate $coordinate;
    private \DateTimeImmutable $fetchedAt;

    protected function setUp(): void
    {
        $this->mapper = new OpenMeteoResponseMapper();
        $this->coordinate = new Coordinate(27.75, 129.05);
        $this->fetchedAt = new \DateTimeImmutable('2026-10-05T11:15:00Z');
    }

    public function testCombinesBothResponsesByTime(): void
    {
        $forecast = $this->mapper->toDomain(
            $this->coordinate,
            $this->fetchedAt,
            new OpenMeteoWeatherResponse([self::T0, self::T1], [4.0, 5.5], [7.0, 8.0], [10.0, 20.0]),
            new OpenMeteoMarineResponse([self::T0, self::T1], [1.2, 1.3], [90.0, 95.0], [6.0, 6.5], [0.8, 0.9], [135.0, 140.0], [9.0, 9.5]),
        );

        self::assertSame($this->coordinate, $forecast->coordinate);
        self::assertEquals($this->fetchedAt, $forecast->fetchedAt);
        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::Available, $forecast->sea);
        self::assertCount(2, $forecast->hours);

        $second = $forecast->hours[1];
        self::assertSame('2026-10-05T12:00:00+00:00', $second->time->format(\DATE_ATOM));
        self::assertSame(5.5, $second->windSpeed);
        self::assertSame(8.0, $second->windGust);
        self::assertSame(20.0, $second->windDirection);
        self::assertSame(1.3, $second->waveHeight);
        self::assertSame(95.0, $second->waveDirection);
        self::assertSame(6.5, $second->wavePeriod);
        self::assertSame(0.9, $second->swellHeight);
        self::assertSame(140.0, $second->swellDirection);
        self::assertSame(9.5, $second->swellPeriod);
    }

    public function testTreatsNegativeAndNanAsMissing(): void
    {
        $forecast = $this->mapper->toDomain(
            $this->coordinate,
            $this->fetchedAt,
            new OpenMeteoWeatherResponse([self::T0, self::T1], [-1.0, \NAN], [7.0, null], [10.0, 20.0]),
            new OpenMeteoMarineResponse([self::T0, self::T1], [-0.1, 1.3], [90.0, 95.0], [6.0, -6.5], [0.8, 0.9], [135.0, 140.0], [9.0, 9.5]),
        );

        self::assertNull($forecast->hours[0]->windSpeed);
        self::assertNull($forecast->hours[1]->windSpeed);
        self::assertNull($forecast->hours[1]->windGust);
        self::assertNull($forecast->hours[0]->waveHeight);
        self::assertNull($forecast->hours[1]->wavePeriod);
        self::assertSame(1.3, $forecast->hours[1]->waveHeight);
    }

    public function testSeaIsNotProvidedWhenAllSeaValuesAreNull(): void
    {
        $forecast = $this->mapper->toDomain(
            new Coordinate(36.65, 138.18),
            $this->fetchedAt,
            OpenMeteoWeatherResponse::fromArray(OpenMeteoFixture::decode('weather.json')),
            OpenMeteoMarineResponse::fromArray(OpenMeteoFixture::decode('marine_inland.json')),
        );

        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::NotProvidedAtLocation, $forecast->sea);
        self::assertCount(74, $forecast->hours);
        self::assertSame(4.0, $forecast->hours[0]->windSpeed);
    }

    public function testMissingWeatherResponseMeansWindFetchFailed(): void
    {
        $forecast = $this->mapper->toDomain(
            $this->coordinate,
            $this->fetchedAt,
            null,
            new OpenMeteoMarineResponse([self::T0], [1.2], [90.0], [6.0], [0.8], [135.0], [9.0]),
        );

        self::assertSame(Availability::FetchFailed, $forecast->wind);
        self::assertSame(Availability::Available, $forecast->sea);
        self::assertNull($forecast->hours[0]->windSpeed);
        self::assertNull($forecast->hours[0]->windGust);
        self::assertNull($forecast->hours[0]->windDirection);
        self::assertSame(1.2, $forecast->hours[0]->waveHeight);
    }

    public function testMissingMarineResponseMeansSeaFetchFailed(): void
    {
        $forecast = $this->mapper->toDomain(
            $this->coordinate,
            $this->fetchedAt,
            new OpenMeteoWeatherResponse([self::T0], [4.0], [7.0], [10.0]),
            null,
        );

        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::FetchFailed, $forecast->sea);
        self::assertNull($forecast->hours[0]->waveHeight);
        self::assertNull($forecast->hours[0]->swellPeriod);
    }

    public function testUsesUnionOfTimesWhenResponsesDiffer(): void
    {
        $forecast = $this->mapper->toDomain(
            $this->coordinate,
            $this->fetchedAt,
            new OpenMeteoWeatherResponse([self::T0, self::T1], [4.0, 5.0], [7.0, 8.0], [10.0, 20.0]),
            new OpenMeteoMarineResponse([self::T1, self::T2], [1.2, 1.3], [90.0, 95.0], [6.0, 6.5], [0.8, 0.9], [135.0, 140.0], [9.0, 9.5]),
        );

        self::assertCount(3, $forecast->hours);
        self::assertNull($forecast->hours[0]->waveHeight);
        self::assertSame(5.0, $forecast->hours[1]->windSpeed);
        self::assertSame(1.2, $forecast->hours[1]->waveHeight);
        self::assertNull($forecast->hours[2]->windSpeed);
    }
}
