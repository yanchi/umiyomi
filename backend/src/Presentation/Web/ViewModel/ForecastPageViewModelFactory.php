<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

use App\Application\Marine\DTO\ForecastStatus;
use App\Application\Marine\DTO\GroupAvailability;
use App\Application\Marine\DTO\HourlyForecastView;
use App\Application\Marine\DTO\MarineForecastResult;
use App\Application\Marine\DTO\MarineForecastView;
use App\Presentation\Web\Input\CoordinateQuery;
use App\Presentation\Web\Input\FeedbackContextParser;

/**
 * 方位の日本語名・日時の書式・行ラベル・数値の書式・欠損の表示など、見せ方の決定をここに集める.
 *
 * @phpstan-import-type Column from ForecastTable
 * @phpstan-import-type Row from ForecastTable
 * @phpstan-import-type Form from ForecastPageViewModel
 * @phpstan-import-type Notice from ForecastPageViewModel
 * @phpstan-import-type FavoriteTarget from ForecastPageViewModel
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
        // 受け付けなかった入力を案内画面に伝える。長い入力で URL が膨らまないよう、案内画面が読む長さに合わせて切る
        $feedbackQuery = [
            'input_lat' => mb_substr($query->rawLatitude, 0, FeedbackContextParser::MAX_INPUT_LENGTH),
            'input_lon' => mb_substr($query->rawLongitude, 0, FeedbackContextParser::MAX_INPUT_LENGTH),
        ];

        return new ForecastPageViewModel($this->form($query, null), null, null, null, [], null, null, null, $feedbackQuery, 'invalid_input', []);
    }

    public function create(CoordinateQuery $query, MarineForecastResult $result): ForecastPageViewModel
    {
        $forecast = $result->forecast;
        if (null === $forecast) {
            return new ForecastPageViewModel($this->form($query, null), $this->notice($result->status, null), null, null, [], null, $this->favoriteTarget($result), $this->inputNotice($query), $this->feedbackQuery($result), $this->analyticsScreen($result->status), $this->analyticsQuery($result));
        }

        $isStale = ForecastStatus::Stale === $result->status;

        return new ForecastPageViewModel(
            form: $this->form($query, $this->staleNotice($forecast)),
            notice: $this->notice($result->status, $forecast),
            location: $this->location($forecast),
            lastUpdated: $this->lastUpdated($forecast, $isStale),
            groupMessages: $this->groupMessages($forecast),
            table: $this->table($forecast),
            favoriteTarget: $this->favoriteTarget($result),
            inputNotice: $this->inputNotice($query),
            feedbackQuery: $this->feedbackQuery($result),
            analyticsScreen: $this->analyticsScreen($result->status),
            analyticsQuery: $this->analyticsQuery($result),
        );
    }

    // Twig に分岐を持たせず、ViewModel の変換として Unit Test できるようにするため、ここで決める
    /**
     * @return 'forecast'|'forecast_unavailable'|'rate_limited'
     */
    private function analyticsScreen(ForecastStatus $status): string
    {
        return match ($status) {
            ForecastStatus::Fresh, ForecastStatus::Stale => 'forecast',
            ForecastStatus::Unavailable => 'forecast_unavailable',
            ForecastStatus::RateLimited => 'rate_limited',
        };
    }

    /**
     * @return array{lat: string, lon: string}
     */
    private function analyticsQuery(MarineForecastResult $result): array
    {
        // 画面に出している地点の書式と食い違わせないため、お気に入りに保存する地点と同じ値から作る。
        // 入力文字列・updated・ignored は含めない
        $target = $this->favoriteTarget($result);

        return ['lat' => $target['latitude'], 'lon' => $target['longitude']];
    }

    private function inputNotice(CoordinateQuery $query): ?string
    {
        return match ($query->ignoredField) {
            'longitude' => 'この地点は緯度欄の「緯度, 経度」から読み取りました。経度欄に入っていた値は使っていません',
            'latitude' => 'この地点は経度欄の「緯度, 経度」から読み取りました。緯度欄に入っていた値は使っていません',
            default => null,
        };
    }

    /**
     * @return Notice|null
     */
    private function notice(ForecastStatus $status, ?MarineForecastView $forecast): ?array
    {
        return match ($status) {
            ForecastStatus::Fresh => null,
            ForecastStatus::Stale => [
                'type' => 'stale',
                'message' => \sprintf('最新の予報を取得できませんでした。表示中は %s 時点の予報です', $forecast?->fetchedAt->format('Y/m/d H:i') ?? ''),
            ],
            ForecastStatus::Unavailable => ['type' => 'unavailable', 'message' => '予報を取得できませんでした。時間をおいて再度お試しください'],
            ForecastStatus::RateLimited => ['type' => 'rate_limited', 'message' => 'しばらく待ってから再度お試しください'],
        };
    }

    /**
     * @return list<string>
     */
    private function groupMessages(MarineForecastView $forecast): array
    {
        return array_values(array_filter(
            [self::groupMessage($forecast->wind, '風'), self::groupMessage($forecast->sea, '波・うねり')],
            static fn (?string $message): bool => null !== $message,
        ));
    }

    private static function groupMessage(GroupAvailability $availability, string $group): ?string
    {
        return match ($availability) {
            GroupAvailability::Available => null,
            GroupAvailability::NotProvidedAtLocation => \sprintf('この地点では%sの予報が得られません', $group),
            GroupAvailability::FetchFailed => \sprintf('%sの予報を取得できませんでした', $group),
        };
    }

    /**
     * @param string|null $staleNotice 入力欄が表示中の地点と異なるときの案内。予報の一覧がない画面では出さない
     *
     * @return Form
     */
    private function form(CoordinateQuery $query, ?string $staleNotice): array
    {
        return [
            'latitude' => $query->rawLatitude,
            'longitude' => $query->rawLongitude,
            'latitudeError' => $query->errors['latitude'] ?? null,
            'longitudeError' => $query->errors['longitude'] ?? null,
            'staleNotice' => $staleNotice,
        ];
    }

    // 入力欄を書き換えたあとに、表示中の一覧が入力欄の地点の予報だと誤解されないようにする（FR-021）。一覧の地点を文言に含める
    private function staleNotice(MarineForecastView $forecast): string
    {
        return \sprintf(
            '入力欄の地点の予報はまだ表示していません。表示中の一覧は %s の予報です。「予報を表示」を押すと、入力欄の地点の予報に切り替わります。',
            $this->location($forecast),
        );
    }

    /**
     * @return FavoriteTarget
     */
    private function favoriteTarget(MarineForecastResult $result): array
    {
        // -0.0 を足し算で 0.0 にして、丸めた結果が -0.0 の座標を「-0.00」と書式化しない
        return [
            'latitude' => \sprintf('%.2f', $result->latitude + 0.0),
            'longitude' => \sprintf('%.2f', $result->longitude + 0.0),
            'label' => self::formatLocation($result->latitude, $result->longitude),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function feedbackQuery(MarineForecastResult $result): array
    {
        $target = $this->favoriteTarget($result);

        // 地点の書式を画面に出している値と食い違わせないため、お気に入りに保存する地点と同じ値から作る。
        // 最終更新は「最終更新：」の表示と同じ日時で、予報がない（取得失敗・回数制限）ときは付けない
        return ['lat' => $target['latitude'], 'lon' => $target['longitude']]
            + (null === $result->forecast ? [] : ['updated' => $result->forecast->fetchedAt->format('Y/m/d H:i')]);
    }

    private function location(MarineForecastView $forecast): string
    {
        return self::formatLocation($forecast->latitude, $forecast->longitude);
    }

    // 予報の有無に関係なく同じ書式で作る。予報が得られない 503・429 でも保存パネルに地点を出すため
    private static function formatLocation(float $latitude, float $longitude): string
    {
        return \sprintf(
            '%s %s° / %s %s°',
            $latitude < 0 ? '南緯' : '北緯',
            number_format(abs($latitude), 2),
            $longitude < 0 ? '西経' : '東経',
            number_format(abs($longitude), 2),
        );
    }

    private function lastUpdated(MarineForecastView $forecast, bool $isStale): string
    {
        $fetchedAt = $forecast->fetchedAt;
        // 代替表示では再取得を試みて失敗した直後なので、再取得の時刻を案内しない
        if ($isStale) {
            return \sprintf('最終更新：%s', $fetchedAt->format('Y/m/d H:i'));
        }
        $next = $forecast->nextRefetchAt;
        // 再取得の時刻は、最終更新と日付が違うときだけ日付を付ける
        $nextLabel = $next->format('Y-m-d') === $fetchedAt->format('Y-m-d') ? $next->format('H:i') : $next->format('m/d H:i');

        return \sprintf('最終更新：%s（%s以降に再取得）', $fetchedAt->format('Y/m/d H:i'), $nextLabel);
    }

    private function table(MarineForecastView $forecast): ForecastTable
    {
        return new ForecastTable(
            $this->columns($forecast->hours),
            $this->rows($forecast->hours, GroupAvailability::Available === $forecast->wind, GroupAvailability::Available === $forecast->sea),
        );
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
    private function rows(array $hours, bool $showWind, bool $showSea): array
    {
        // 取得できなかったグループの行は「—」で埋めず行ごと出さない。欠損と区別し、代わりに groupMessages で理由を示すため
        $windDefinitions = [
            ['風速 (m/s)', static fn (HourlyForecastView $h): string => self::number($h->windSpeed)],
            ['突風 (m/s)', static fn (HourlyForecastView $h): string => self::number($h->windGust)],
            ['風向', static fn (HourlyForecastView $h): string => self::direction($h->windDirection)],
        ];
        $seaDefinitions = [
            ['波高 (m)', static fn (HourlyForecastView $h): string => self::number($h->waveHeight)],
            ['波向', static fn (HourlyForecastView $h): string => self::direction($h->waveDirection)],
            ['波周期 (秒)', static fn (HourlyForecastView $h): string => self::number($h->wavePeriod)],
            ['うねり高さ (m)', static fn (HourlyForecastView $h): string => self::number($h->swellHeight)],
            ['うねり向き', static fn (HourlyForecastView $h): string => self::direction($h->swellDirection)],
            ['うねり周期 (秒)', static fn (HourlyForecastView $h): string => self::number($h->swellPeriod)],
        ];

        $definitions = [...($showWind ? $windDefinitions : []), ...($showSea ? $seaDefinitions : [])];

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
