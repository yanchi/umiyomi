<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Marine;

use App\Domain\Marine\ForecastPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForecastPeriodTest extends TestCase
{
    public function testStartingAtTruncatesToUtcHour(): void
    {
        $period = ForecastPeriod::startingAt(new \DateTimeImmutable('2026-10-05T20:15:42.5+09:00'), 73);

        self::assertSame('2026-10-05T11:00:00+00:00', $period->from->format(\DATE_ATOM));
        self::assertSame('2026-10-08T12:00:00+00:00', $period->to->format(\DATE_ATOM));
        self::assertSame('UTC', $period->from->getTimezone()->getName());
    }

    public function testAcceptsHourInOtherTimeZone(): void
    {
        $period = new ForecastPeriod(
            new \DateTimeImmutable('2026-10-05T20:00:00+09:00'),
            new \DateTimeImmutable('2026-10-05T12:00:00Z'),
        );

        self::assertSame('2026-10-05T11:00:00+00:00', $period->from->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'from equals to' => ['2026-10-05T11:00:00Z', '2026-10-05T11:00:00Z'];
        yield 'from after to' => ['2026-10-05T12:00:00Z', '2026-10-05T11:00:00Z'];
        yield 'from not on the hour' => ['2026-10-05T11:30:00Z', '2026-10-05T12:00:00Z'];
        yield 'to has seconds' => ['2026-10-05T11:00:00Z', '2026-10-05T12:00:01Z'];
        yield 'to has microseconds' => ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00.5Z'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidPeriod(string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForecastPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    public function testStartingAtRejectsNonPositiveHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ForecastPeriod::startingAt(new \DateTimeImmutable('2026-10-05T11:15:00Z'), 0);
    }
}
