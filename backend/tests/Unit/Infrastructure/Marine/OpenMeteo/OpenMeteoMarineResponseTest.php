<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\OpenMeteo;

use App\Infrastructure\Marine\OpenMeteo\OpenMeteoMarineResponse;
use App\Tests\Support\OpenMeteoFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenMeteoMarineResponseTest extends TestCase
{
    public function testReadsFixture(): void
    {
        $response = OpenMeteoMarineResponse::fromArray(OpenMeteoFixture::decode('marine.json'));

        self::assertCount(74, $response->time);
        self::assertSame(1.2, $response->waveHeight[0]);
        self::assertSame(90.0, $response->waveDirection[0]);
        self::assertSame(6.0, $response->wavePeriod[0]);
        self::assertSame(0.8, $response->swellWaveHeight[0]);
        self::assertSame(135.0, $response->swellWaveDirection[0]);
        self::assertSame(9.0, $response->swellWavePeriod[0]);
        self::assertNull($response->waveHeight[72]);
        self::assertSame(-1.0, $response->waveHeight[73]);
    }

    public function testReadsInlandFixtureWithAllNulls(): void
    {
        $response = OpenMeteoMarineResponse::fromArray(OpenMeteoFixture::decode('marine_inland.json'));

        self::assertCount(74, $response->time);
        self::assertSame([null], array_values(array_unique($response->waveHeight, \SORT_REGULAR)));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedProvider(): iterable
    {
        $valid = OpenMeteoFixture::decode('marine.json');
        \assert(\is_array($valid['hourly']));

        $withoutKey = $valid;
        $hourly = $valid['hourly'];
        unset($hourly['swell_wave_period']);
        $withoutKey['hourly'] = $hourly;
        yield 'missing key' => [$withoutKey];

        $longValues = $valid;
        $hourly = $valid['hourly'];
        \assert(\is_array($hourly['wave_height']));
        $hourly['wave_height'][] = 1.0;
        $longValues['hourly'] = $hourly;
        yield 'length differs from time' => [$longValues];

        $boolValue = $valid;
        $hourly = $valid['hourly'];
        \assert(\is_array($hourly['wave_period']));
        $hourly['wave_period'][0] = true;
        $boolValue['hourly'] = $hourly;
        yield 'non numeric value' => [$boolValue];

        $notList = $valid;
        $hourly = $valid['hourly'];
        $hourly['wave_direction'] = 'none';
        $notList['hourly'] = $hourly;
        yield 'values are not a list' => [$notList];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('malformedProvider')]
    public function testRejectsMalformedResponse(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);

        OpenMeteoMarineResponse::fromArray($data);
    }
}
