<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\HourlyForecast;
use App\Domain\Marine\MarineForecast;

/**
 * Provider 固有 DTO から Domain の予報を組み立てる。単位・欠損・取得状況の解釈はここだけで行う.
 */
final readonly class OpenMeteoResponseMapper
{
    /**
     * @param OpenMeteoWeatherResponse|null $weather null は取得に失敗したことを表す
     * @param OpenMeteoMarineResponse|null  $marine  null は取得に失敗したことを表す
     */
    public function toDomain(
        Coordinate $coordinate,
        \DateTimeImmutable $fetchedAt,
        ?OpenMeteoWeatherResponse $weather,
        ?OpenMeteoMarineResponse $marine,
    ): MarineForecast {
        /** @var array<int, array{wind: array{float|null, float|null, float|null}, sea: array{float|null, float|null, float|null, float|null, float|null, float|null}}> $rows */
        $rows = [];
        $emptyWind = [null, null, null];
        $emptySea = [null, null, null, null, null, null];

        if (null !== $weather) {
            foreach ($weather->time as $i => $timestamp) {
                $rows[$timestamp] ??= ['wind' => $emptyWind, 'sea' => $emptySea];
                $rows[$timestamp]['wind'] = [
                    self::sanitize($weather->windSpeed10m[$i] ?? null),
                    self::sanitize($weather->windGusts10m[$i] ?? null),
                    self::sanitize($weather->windDirection10m[$i] ?? null),
                ];
            }
        }
        if (null !== $marine) {
            foreach ($marine->time as $i => $timestamp) {
                $rows[$timestamp] ??= ['wind' => $emptyWind, 'sea' => $emptySea];
                $rows[$timestamp]['sea'] = [
                    self::sanitize($marine->waveHeight[$i] ?? null),
                    self::sanitize($marine->waveDirection[$i] ?? null),
                    self::sanitize($marine->wavePeriod[$i] ?? null),
                    self::sanitize($marine->swellWaveHeight[$i] ?? null),
                    self::sanitize($marine->swellWaveDirection[$i] ?? null),
                    self::sanitize($marine->swellWavePeriod[$i] ?? null),
                ];
            }
        }
        ksort($rows);

        $hours = [];
        foreach ($rows as $timestamp => $row) {
            [$windSpeed, $windGust, $windDirection] = $row['wind'];
            [$waveHeight, $waveDirection, $wavePeriod, $swellHeight, $swellDirection, $swellPeriod] = $row['sea'];
            $hours[] = new HourlyForecast(
                new \DateTimeImmutable('@'.$timestamp),
                $windSpeed,
                $windGust,
                $windDirection,
                $waveHeight,
                $waveDirection,
                $wavePeriod,
                $swellHeight,
                $swellDirection,
                $swellPeriod,
            );
        }

        return new MarineForecast(
            $coordinate,
            $fetchedAt,
            self::availability(null !== $weather, array_any($hours, static fn (HourlyForecast $hour): bool => $hour->hasWindValue())),
            self::availability(null !== $marine, array_any($hours, static fn (HourlyForecast $hour): bool => $hour->hasSeaValue())),
            $hours,
        );
    }

    /**
     * 風速・波高・周期・角度はどれも負にならないため、負の値と NaN は提供元の欠損値として扱う.
     */
    private static function sanitize(?float $value): ?float
    {
        if (null === $value || is_nan($value) || $value < 0.0) {
            return null;
        }

        return $value;
    }

    /**
     * 全時刻が null のグループは「この地点では提供されない」とみなす（内陸など。research R2）.
     */
    private static function availability(bool $fetched, bool $hasValue): Availability
    {
        if (!$fetched) {
            return Availability::FetchFailed;
        }

        return $hasValue ? Availability::Available : Availability::NotProvidedAtLocation;
    }
}
