<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

use App\Application\Marine\DTO\HourlyForecastView;
use App\Application\Marine\DTO\MarineForecastResult;
use App\Application\Marine\DTO\MarineForecastView;
use App\Presentation\Web\Input\CoordinateQuery;

/**
 * 方位の日本語名・日時の書式・行ラベル・数値の書式・欠損の表示など、見せ方の決定をここに集める.
 *
 * @phpstan-import-type Column from ForecastTable
 * @phpstan-import-type Row from ForecastTable
 * @phpstan-import-type Form from ForecastPageViewModel
 */
final readonly class ForecastPageViewModelFactory
{
    // 0 と区別するため、欠損は数値に見えない記号で表す（FR-011）
    private const string MISSING = '—';

    private const array DIRECTION_NAMES = [
        'N' => '北', 'NNE' => '北北東', 'NE' => '北東', 'ENE' => '東北東',
        'E' => '東', 'ESE' => '東南東', 'SE' => '南東', 'SSE' => '南南東',
        'S' => '南', 'SSW' => '南南西', 'SW' => '南西', 'WSW' => '西南西',
        'W' => '西', 'WNW' => '西北西', 'NW' => '北西', 'NNW' => '北北西',
    ];

    private const array WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function createForInvalidInput(CoordinateQuery $query): ForecastPageViewModel
    {
        return new ForecastPageViewModel($this->form($query), null, null, null, [], null);
    }

    public function create(CoordinateQuery $query, MarineForecastResult $result): ForecastPageViewModel
    {
        $forecast = $result->forecast;
        if (null === $forecast) {
            return new ForecastPageViewModel($this->form($query), null, null, null, [], null);
        }

        return new ForecastPageViewModel(
            form: $this->form($query),
            notice: null,
            location: $this->location($forecast),
            lastUpdated: $this->lastUpdated($forecast),
            groupMessages: [],
            table: $this->table($forecast),
        );
    }

    /**
     * @return Form
     */
    private function form(CoordinateQuery $query): array
    {
        return [
            'latitude' => $query->rawLatitude,
            'longitude' => $query->rawLongitude,
            'latitudeError' => $query->errors['latitude'] ?? null,
            'longitudeError' => $query->errors['longitude'] ?? null,
        ];
    }

    private function location(MarineForecastView $forecast): string
    {
        return \sprintf(
            '%s %s° / %s %s°',
            $forecast->latitude < 0 ? '南緯' : '北緯',
            number_format(abs($forecast->latitude), 2),
            $forecast->longitude < 0 ? '西経' : '東経',
            number_format(abs($forecast->longitude), 2),
        );
    }

    private function lastUpdated(MarineForecastView $forecast): string
    {
        $fetchedAt = $forecast->fetchedAt;
        $next = $forecast->nextRefetchAt;
        // 再取得の時刻は、最終更新と日付が違うときだけ日付を付ける
        $nextLabel = $next->format('Y-m-d') === $fetchedAt->format('Y-m-d') ? $next->format('H:i') : $next->format('m/d H:i');

        return \sprintf('最終更新：%s（%s以降に再取得）', $fetchedAt->format('Y/m/d H:i'), $nextLabel);
    }

    private function table(MarineForecastView $forecast): ForecastTable
    {
        return new ForecastTable($this->columns($forecast->hours), $this->rows($forecast->hours));
    }

    /**
     * @param list<HourlyForecastView> $hours
     *
     * @return list<Column>
     */
    private function columns(array $hours): array
    {
        $columns = [];
        $previousDate = null;
        $threeHourlyFrom = null === ($hours[0] ?? null) ? null : $hours[0]->time->modify('+24 hours');
        $threeHourlyMarked = false;

        foreach ($hours as $hour) {
            $date = $hour->time->format('Y-m-d');
            $isFirst = null === $previousDate;
            $startsNewDay = !$isFirst && $date !== $previousDate;
            // 先頭の列から 24 時間後以降が 3 時間ごとの区間（ForecastTimeline の選び方と同じ基準）
            $startsThreeHourly = !$threeHourlyMarked && null !== $threeHourlyFrom && $hour->time >= $threeHourlyFrom;
            $threeHourlyMarked = $threeHourlyMarked || $startsThreeHourly;

            $columns[] = [
                'dateLabel' => $isFirst || $startsNewDay
                    ? \sprintf('%s(%s)', $hour->time->format('m/d'), self::WEEKDAYS[(int) $hour->time->format('w')])
                    : null,
                'timeLabel' => $hour->time->format('H:i'),
                'startsNewDay' => $startsNewDay,
                'startsThreeHourly' => $startsThreeHourly,
            ];
            $previousDate = $date;
        }

        return $columns;
    }

    /**
     * @param list<HourlyForecastView> $hours
     *
     * @return list<Row>
     */
    private function rows(array $hours): array
    {
        $definitions = [
            ['風速 (m/s)', static fn (HourlyForecastView $h): string => self::number($h->windSpeed)],
            ['突風 (m/s)', static fn (HourlyForecastView $h): string => self::number($h->windGust)],
            ['風向', static fn (HourlyForecastView $h): string => self::direction($h->windDirection)],
            ['波高 (m)', static fn (HourlyForecastView $h): string => self::number($h->waveHeight)],
            ['波向', static fn (HourlyForecastView $h): string => self::direction($h->waveDirection)],
            ['波周期 (秒)', static fn (HourlyForecastView $h): string => self::number($h->wavePeriod)],
            ['うねり高さ (m)', static fn (HourlyForecastView $h): string => self::number($h->swellHeight)],
            ['うねり向き', static fn (HourlyForecastView $h): string => self::direction($h->swellDirection)],
            ['うねり周期 (秒)', static fn (HourlyForecastView $h): string => self::number($h->swellPeriod)],
        ];

        $rows = [];
        foreach ($definitions as [$label, $format]) {
            $rows[] = ['label' => $label, 'cells' => array_map($format, $hours)];
        }

        return $rows;
    }

    private static function number(?float $value): string
    {
        return null === $value ? self::MISSING : number_format($value, 1);
    }

    private static function direction(?string $identifier): string
    {
        return null === $identifier ? self::MISSING : (self::DIRECTION_NAMES[$identifier] ?? self::MISSING);
    }
}
