<?php

declare(strict_types=1);

namespace App\Tests\External;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\ForecastPeriod;
use App\Infrastructure\Marine\OpenMeteo\OpenMeteoMarineForecastProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 実際の Open-Meteo を呼ぶ。通常のテスト実行では動かさず、composer test:external で明示的に実行する.
 */
final class OpenMeteoMarineForecastProviderTest extends KernelTestCase
{
    private OpenMeteoMarineForecastProvider $provider;
    private ForecastPeriod $period;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(OpenMeteoMarineForecastProvider::class);
        $this->period = ForecastPeriod::startingAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 73);
    }

    public function testSeaLocationHasWindAndSeaForecast(): void
    {
        $forecast = $this->provider->forecast(new Coordinate(27.75, 129.05), $this->period);

        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::Available, $forecast->sea);
        // start_hour / end_hour は両端を含むため 74 件
        self::assertCount(74, $forecast->hours);
        self::assertEquals($this->period->from, $forecast->hours[0]->time);
        self::assertEquals($this->period->to, $forecast->hours[73]->time);
    }

    public function testInlandLocationHasNoSeaForecast(): void
    {
        $forecast = $this->provider->forecast(new Coordinate(36.65, 138.18), $this->period);

        self::assertSame(Availability::Available, $forecast->wind);
        self::assertSame(Availability::NotProvidedAtLocation, $forecast->sea);
    }
}
