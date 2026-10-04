<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

use App\Application\Marine\Port\Clock;
use App\Application\Marine\Port\MarineForecastProvider;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\FetchFailure;
use App\Domain\Marine\ForecastPeriod;
use App\Domain\Marine\MarineForecast;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OpenMeteoMarineForecastProvider implements MarineForecastProvider
{
    private const string WEATHER_HOURLY = 'wind_speed_10m,wind_gusts_10m,wind_direction_10m';
    private const string MARINE_HOURLY = 'wave_height,wave_direction,wave_period,swell_wave_height,swell_wave_direction,swell_wave_period';

    public function __construct(
        #[Target('open_meteo_weather.client')]
        private HttpClientInterface $weatherClient,
        #[Target('open_meteo_marine.client')]
        private HttpClientInterface $marineClient,
        private OpenMeteoResponseMapper $mapper,
        private Clock $clock,
    ) {
    }

    public function forecast(Coordinate $coordinate, ForecastPeriod $period): MarineForecast
    {
        $fetchedAt = $this->clock->now();

        // HttpClient のレスポンスは遅延評価なので、両方のリクエストを先に発行してから読むと並列に取得できる
        $weatherResponse = $this->weatherClient->request('GET', '/v1/forecast', [
            'query' => $this->query($coordinate, $period, self::WEATHER_HOURLY) + ['wind_speed_unit' => 'ms'],
        ]);
        $marineResponse = $this->marineClient->request('GET', '/v1/marine', [
            'query' => $this->query($coordinate, $period, self::MARINE_HOURLY),
        ]);

        try {
            $weather = OpenMeteoWeatherResponse::fromArray($this->decode($weatherResponse));
            $marine = OpenMeteoMarineResponse::fromArray($this->decode($marineResponse));
        } catch (ExceptionInterface|\UnexpectedValueException $e) {
            throw new FetchFailure(\sprintf('Open-Meteo request failed: %s', $e->getMessage()), previous: $e);
        }

        return $this->mapper->toDomain($coordinate, $fetchedAt, $weather, $marine);
    }

    /**
     * 時刻は UTC の unixtime で受け取る。timezone=Asia/Tokyo の文字列をパースするとタイムゾーンを取り違えうるため、境界を UTC に統一する.
     *
     * @return array<string, string>
     */
    private function query(Coordinate $coordinate, ForecastPeriod $period, string $hourly): array
    {
        return [
            'latitude' => \sprintf('%.2f', $coordinate->latitude()),
            'longitude' => \sprintf('%.2f', $coordinate->longitude()),
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'start_hour' => $period->from->format('Y-m-d\TH:i'),
            'end_hour' => $period->to->format('Y-m-d\TH:i'),
            'hourly' => $hourly,
        ];
    }

    /**
     * @return array<mixed>
     *
     * @throws ExceptionInterface
     */
    private function decode(ResponseInterface $response): array
    {
        return $response->toArray();
    }
}
