<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\OpenMeteo;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\ForecastPeriod;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoMarineForecastProvider;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoResponseMapper;
use App\Tests\Support\FixedClock;
use App\Tests\Support\OpenMeteoFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OpenMeteoMarineForecastProviderTest extends TestCase
{
    private FixedClock $clock;
    private ForecastPeriod $period;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-05T11:15:00Z');
        $this->period = ForecastPeriod::startingAt($this->clock->now(), 73);
    }

    public function testRequestsBothApisWithExpectedQuery(): void
    {
        /** @var list<array{string, string}> $requests */
        $requests = [];
        $record = static function (string $body) use (&$requests): \Closure {
            return static function (string $method, string $url) use ($body, &$requests): ResponseInterface {
                $requests[] = [$method, $url];

                return new MockResponse($body);
            };
        };

        $provider = $this->provider(
            new MockHttpClient($record(OpenMeteoFixture::body('weather.json')), 'https://api.open-meteo.com'),
            new MockHttpClient($record(OpenMeteoFixture::body('marine.json')), 'https://marine-api.open-meteo.com'),
        );

        $provider->forecast(new Coordinate(27.75, 129.05), $this->period);

        self::assertCount(2, $requests);
        [$weather, $marine] = $requests;

        self::assertSame('GET', $weather[0]);
        self::assertSame('https://api.open-meteo.com/v1/forecast', strtok($weather[1], '?'));
        self::assertSame([
            'latitude' => '27.75',
            'longitude' => '129.05',
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'start_hour' => '2026-10-05T11:00',
            'end_hour' => '2026-10-08T12:00',
            'hourly' => 'wind_speed_10m,wind_gusts_10m,wind_direction_10m',
            'wind_speed_unit' => 'ms',
        ], $this->query($weather[1]));

        self::assertSame('GET', $marine[0]);
        self::assertSame('https://marine-api.open-meteo.com/v1/marine', strtok($marine[1], '?'));
        self::assertSame([
            'latitude' => '27.75',
            'longitude' => '129.05',
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'start_hour' => '2026-10-05T11:00',
            'end_hour' => '2026-10-08T12:00',
            'hourly' => 'wave_height,wave_direction,wave_period,swell_wave_height,swell_wave_direction,swell_wave_period',
        ], $this->query($marine[1]));
    }

    public function testFormatsNegativeCoordinateWithTwoDecimals(): void
    {
        $urls = [];
        $client = static function (string $body) use (&$urls): MockHttpClient {
            return new MockHttpClient(static function (string $method, string $url) use ($body, &$urls): MockResponse {
                $urls[] = $url;

                return new MockResponse($body);
            }, 'https://example.test');
        };

        $this->provider($client(OpenMeteoFixture::body('weather.json')), $client(OpenMeteoFixture::body('marine.json')))
            ->forecast(new Coordinate(-0.5, -120.0), $this->period);

        self::assertCount(2, $urls);
        foreach ($urls as $url) {
            $query = $this->query($url);
            self::assertSame('-0.50', $query['latitude']);
            self::assertSame('-120.00', $query['longitude']);
        }
    }

    public function testBuildsForecastFromResponses(): void
    {
        $provider = $this->provider(
            new MockHttpClient(new MockResponse(OpenMeteoFixture::body('weather.json')), 'https://api.open-meteo.com'),
            new MockHttpClient(new MockResponse(OpenMeteoFixture::body('marine.json')), 'https://marine-api.open-meteo.com'),
        );

        $forecast = $provider->forecast(new Coordinate(27.75, 129.05), $this->period);

        self::assertSame('27.75_129.05', $forecast->coordinate->key());
        self::assertEquals($this->clock->now(), $forecast->fetchedAt);
        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::Available, $forecast->sea);
        self::assertCount(74, $forecast->hours);
        self::assertSame(4.0, $forecast->hours[0]->windSpeed);
        self::assertSame(1.2, $forecast->hours[0]->waveHeight);
    }

    private function provider(MockHttpClient $weatherClient, MockHttpClient $marineClient): OpenMeteoMarineForecastProvider
    {
        return new OpenMeteoMarineForecastProvider($weatherClient, $marineClient, new OpenMeteoResponseMapper(), $this->clock);
    }

    /**
     * @return array<mixed>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $query;
    }
}
