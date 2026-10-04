<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Marine;

use App\Domain\Marine\ForecastTimeline;
use App\Domain\Marine\HourlyForecast;
use App\Tests\Support\MarineForecastBuilder;
use PHPUnit\Framework\TestCase;

final class ForecastTimelineTest extends TestCase
{
    private \DateTimeZone $tokyo;

    protected function setUp(): void
    {
        $this->tokyo = new \DateTimeZone('Asia/Tokyo');
    }

    public function testSelectsHourlyForFirst24HoursThenEveryThreeHours(): void
    {
        // JST 19:00 からの 80 時間分。h0（JST 20:00）より前と h0+72h より後を含む
        $forecast = MarineForecastBuilder::create()
            ->hoursFrom(new \DateTimeImmutable('2026-10-05T10:00:00Z'), 80)
            ->build();

        $selected = ForecastTimeline::select($forecast, new \DateTimeImmutable('2026-10-05T11:15:00Z'), $this->tokyo);
        $labels = $this->labels($selected);

        self::assertCount(40, $selected);
        self::assertSame('10/05 20:00', $labels[0]);
        self::assertSame('10/06 19:00', $labels[23]);
        // 24 時間後からは日本時間の 3 の倍数の時だけ
        self::assertSame('10/06 21:00', $labels[24]);
        self::assertSame('10/07 00:00', $labels[25]);
        self::assertSame('10/08 18:00', $labels[39]);
    }

    public function testIncludesExactly72HoursAhead(): void
    {
        $forecast = MarineForecastBuilder::create()
            ->hoursFrom(new \DateTimeImmutable('2026-10-05T12:00:00Z'), 80)
            ->build();

        // JST 21:30 → h0 = JST 21:00（3 の倍数）なので h0+24h と h0+72h の両方が入る
        $selected = ForecastTimeline::select($forecast, new \DateTimeImmutable('2026-10-05T12:30:00Z'), $this->tokyo);
        $labels = $this->labels($selected);

        self::assertCount(24 + 17, $selected);
        self::assertSame('10/05 21:00', $labels[0]);
        self::assertSame('10/06 21:00', $labels[24]);
        self::assertSame('10/08 21:00', $labels[40]);
    }

    public function testSkipsHoursMissingFromForecast(): void
    {
        // 3 時間前に取得した 73 時間分の予報：末尾が 72 時間先まで届かない
        $forecast = MarineForecastBuilder::create()
            ->hoursFrom(new \DateTimeImmutable('2026-10-05T08:00:00Z'), 73)
            ->build();

        $selected = ForecastTimeline::select($forecast, new \DateTimeImmutable('2026-10-05T11:15:00Z'), $this->tokyo);
        $labels = $this->labels($selected);

        // 最後のデータは 2026-10-08T08:00Z = JST 10/08 17:00 → 3 の倍数の最後は 15:00
        self::assertSame('10/05 20:00', $labels[0]);
        self::assertSame('10/08 15:00', $labels[array_key_last($labels)]);
        self::assertCount(24 + 15, $selected);
    }

    public function testReturnsEmptyWhenForecastIsEntirelyInThePast(): void
    {
        $forecast = MarineForecastBuilder::create()
            ->hoursFrom(new \DateTimeImmutable('2026-10-01T00:00:00Z'), 24)
            ->build();

        self::assertSame([], ForecastTimeline::select($forecast, new \DateTimeImmutable('2026-10-05T11:15:00Z'), $this->tokyo));
    }

    /**
     * @param list<HourlyForecast> $hours
     *
     * @return list<string>
     */
    private function labels(array $hours): array
    {
        return array_map(fn (HourlyForecast $hour): string => $hour->time->setTimezone($this->tokyo)->format('m/d H:i'), $hours);
    }
}
