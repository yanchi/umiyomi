<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Marine;

use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\HourlyForecast;
use App\Domain\Marine\MarineForecast;
use App\Tests\Support\MarineForecastBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarineForecastTest extends TestCase
{
    private const string FETCHED_AT = '2026-10-05T11:15:00Z';

    public function testIsReusableWithinOneHour(): void
    {
        $forecast = MarineForecastBuilder::create()->fetchedAt(new \DateTimeImmutable(self::FETCHED_AT))->build();

        self::assertTrue($forecast->isReusableAt(new \DateTimeImmutable('2026-10-05T12:14:59Z')));
        self::assertFalse($forecast->isReusableAt(new \DateTimeImmutable('2026-10-05T12:15:00Z')));
    }

    public function testIsFallbackUsableWithin24Hours(): void
    {
        $forecast = MarineForecastBuilder::create()->fetchedAt(new \DateTimeImmutable(self::FETCHED_AT))->build();

        self::assertTrue($forecast->isFallbackUsableAt(new \DateTimeImmutable('2026-10-06T11:14:59Z')));
        self::assertFalse($forecast->isFallbackUsableAt(new \DateTimeImmutable('2026-10-06T11:15:00Z')));
    }

    public function testNextRefetchAtIsOneHourAfterFetch(): void
    {
        $forecast = MarineForecastBuilder::create()->fetchedAt(new \DateTimeImmutable('2026-10-05T20:15:00+09:00'))->build();

        self::assertSame('2026-10-05T12:15:00+00:00', $forecast->nextRefetchAt()->format(\DATE_ATOM));
    }

    public function testFetchedAtIsStoredInUtc(): void
    {
        $forecast = MarineForecastBuilder::create()->fetchedAt(new \DateTimeImmutable('2026-10-05T20:15:00+09:00'))->build();

        self::assertSame('UTC', $forecast->fetchedAt->getTimezone()->getName());
        self::assertSame('2026-10-05T11:15:00+00:00', $forecast->fetchedAt->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{Availability, Availability, bool}>
     */
    public static function completenessProvider(): iterable
    {
        yield 'both available' => [Availability::Available, Availability::Available, true];
        yield 'sea not provided' => [Availability::Available, Availability::NotProvidedAtLocation, true];
        yield 'sea failed' => [Availability::Available, Availability::FetchFailed, false];
        yield 'wind failed' => [Availability::FetchFailed, Availability::Available, false];
    }

    #[DataProvider('completenessProvider')]
    public function testIsComplete(Availability $wind, Availability $sea, bool $expected): void
    {
        $forecast = MarineForecastBuilder::create()->wind($wind)->sea($sea)->build();

        self::assertSame($expected, $forecast->isComplete());
    }

    public function testRejectsBothGroupsFailed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MarineForecastBuilder::create()->wind(Availability::FetchFailed)->sea(Availability::FetchFailed)->build();
    }

    /**
     * @return iterable<string, array{Availability, Availability}>
     */
    public static function valueInUnavailableGroupProvider(): iterable
    {
        yield 'wind failed' => [Availability::FetchFailed, Availability::Available];
        yield 'wind not provided' => [Availability::NotProvidedAtLocation, Availability::Available];
        yield 'sea failed' => [Availability::Available, Availability::FetchFailed];
        yield 'sea not provided' => [Availability::Available, Availability::NotProvidedAtLocation];
    }

    #[DataProvider('valueInUnavailableGroupProvider')]
    public function testRejectsValuesInUnavailableGroup(Availability $wind, Availability $sea): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MarineForecast(
            new Coordinate(27.75, 129.05),
            new \DateTimeImmutable(self::FETCHED_AT),
            $wind,
            $sea,
            [$this->hour('2026-10-05T11:00:00Z', 1.0)],
        );
    }

    public function testRejectsUnorderedHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MarineForecastBuilder::create()->withHours([
            $this->hour('2026-10-05T12:00:00Z'),
            $this->hour('2026-10-05T11:00:00Z'),
        ])->build();
    }

    public function testRejectsDuplicatedHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MarineForecastBuilder::create()->withHours([
            $this->hour('2026-10-05T11:00:00Z'),
            $this->hour('2026-10-05T20:00:00+09:00'),
        ])->build();
    }

    private function hour(string $time, ?float $value = null): HourlyForecast
    {
        return new HourlyForecast(new \DateTimeImmutable($time), $value, $value, $value, $value, $value, $value, $value, $value, $value);
    }
}
