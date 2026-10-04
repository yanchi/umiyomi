<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 表示する時刻を選ぶ。直近 24 時間は細かく、その先は列数を抑えて 72 時間先まで見渡せるようにする（FR-003）.
 */
final class ForecastTimeline
{
    private const int HOURLY_SPAN = 24;
    private const int TOTAL_SPAN = 72;
    private const int COARSE_STEP = 3;

    private function __construct()
    {
    }

    /**
     * @return list<HourlyForecast>
     */
    public static function select(MarineForecast $forecast, \DateTimeImmutable $now, \DateTimeZone $displayTimeZone): array
    {
        $local = $now->setTimezone($displayTimeZone);
        $h0 = $local->setTime((int) $local->format('G'), 0);
        $hourlyEnd = $h0->modify(\sprintf('+%d hours', self::HOURLY_SPAN));
        $end = $h0->modify(\sprintf('+%d hours', self::TOTAL_SPAN));

        $selected = [];
        foreach ($forecast->hours as $hour) {
            if ($hour->time < $h0 || $hour->time > $end) {
                continue;
            }
            // 3 時間ごとの区間は表示タイムゾーンの時で揃える（00/03/06…時）。利用者が読む時刻が切りのよい値になるため
            if ($hour->time >= $hourlyEnd && 0 !== (int) $hour->time->setTimezone($displayTimeZone)->format('G') % self::COARSE_STEP) {
                continue;
            }
            $selected[] = $hour;
        }

        return $selected;
    }
}
