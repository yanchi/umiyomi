<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

use App\Application\Marine\Port\Clock;
use App\Application\Marine\Port\MarineForecastProvider;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\FetchFailure;
use App\Domain\Marine\ForecastPeriod;
use App\Domain\Marine\MarineForecast;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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

        // 片方の API だけ失敗しても、取得できたグループは表示する（research R3）。失敗したグループは null として Mapper に渡す
        $weatherError = null;
        $marineError = null;
        try {
            $weather = OpenMeteoWeatherResponse::fromArray($this->decode($weatherResponse));
        } catch (ExceptionInterface|\UnexpectedValueException $e) {
            $weather = null;
            $weatherError = $e;
        }
        try {
            $marine = OpenMeteoMarineResponse::fromArray($this->decode($marineResponse));
        } catch (ExceptionInterface|\UnexpectedValueException $e) {
            $marine = null;
            $marineError = $e;
        }

        // 原因は画面に出さず、調査用にログへ残す。両方失敗した場合も UseCase は前回の予報で代替するだけなので、ここで記録する
        if (null !== $weatherError) {
            $this->logger->warning('Open-Meteo weather request failed: {reason}', ['reason' => $weatherError->getMessage(), 'exception' => $weatherError]);
        }
        if (null !== $marineError) {
            $this->logger->warning('Open-Meteo marine request failed: {reason}', ['reason' => $marineError->getMessage(), 'exception' => $marineError]);
        }
        if (null !== $weatherError && null !== $marineError) {
            throw new FetchFailure(\sprintf('Open-Meteo weather and marine requests failed. weather: %s / marine: %s', $weatherError->getMessage(), $marineError->getMessage()), previous: $marineError);
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
