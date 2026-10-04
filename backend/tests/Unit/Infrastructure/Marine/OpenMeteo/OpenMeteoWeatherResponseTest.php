<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\OpenMeteo;

use App\Infrastructure\Marine\OpenMeteo\OpenMeteoWeatherResponse;
use App\Tests\Support\OpenMeteoFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenMeteoWeatherResponseTest extends TestCase
{
    public function testReadsFixture(): void
    {
        $response = OpenMeteoWeatherResponse::fromArray(OpenMeteoFixture::decode('weather.json'));

        self::assertCount(74, $response->time);
        self::assertSame(1791198000, $response->time[0]);
        self::assertSame(4.0, $response->windSpeed10m[0]);
        self::assertSame(0.0, $response->windDirection10m[0]);
        self::assertNull($response->windSpeed10m[70]);
        self::assertSame(-1.0, $response->windSpeed10m[71]);
        self::assertCount(74, $response->windGusts10m);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedProvider(): iterable
    {
        $valid = OpenMeteoFixture::decode('weather.json');
        \assert(\is_array($valid['hourly']));

        $withoutHourly = $valid;
        unset($withoutHourly['hourly']);
        yield 'no hourly' => [$withoutHourly];

        $withoutKey = $valid;
        $hourly = $valid['hourly'];
        unset($hourly['wind_gusts_10m']);
        $withoutKey['hourly'] = $hourly;
        yield 'missing key' => [$withoutKey];

        $shortValues = $valid;
        $hourly = $valid['hourly'];
        $hourly['wind_speed_10m'] = [1.0, 2.0];
        $shortValues['hourly'] = $hourly;
        yield 'length differs from time' => [$shortValues];

        $stringValue = $valid;
        $hourly = $valid['hourly'];
        \assert(\is_array($hourly['wind_direction_10m']));
        $hourly['wind_direction_10m'][3] = 'N';
        $stringValue['hourly'] = $hourly;
        yield 'non numeric value' => [$stringValue];

        $stringTime = $valid;
        $hourly = $valid['hourly'];
        \assert(\is_array($hourly['time']));
        $hourly['time'][0] = '2026-10-05T11:00';
        $stringTime['hourly'] = $hourly;
        yield 'time is not unixtime' => [$stringTime];

        yield 'error response' => [['error' => true, 'reason' => 'Latitude must be in range of -90 to 90°.']];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('malformedProvider')]
    public function testRejectsMalformedResponse(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);

        OpenMeteoWeatherResponse::fromArray($data);
    }
}
