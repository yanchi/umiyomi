<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\ViewModel;

use App\Application\Marine\DTO\GroupAvailability;
use App\Application\Marine\DTO\HourlyForecastView;
use App\Application\Marine\DTO\MarineForecastResult;
use App\Application\Marine\DTO\MarineForecastView;
use App\Presentation\Web\Input\CoordinateNotationParser;
use App\Presentation\Web\Input\CoordinateQuery;
use App\Presentation\Web\Input\CoordinateQueryParser;
use App\Presentation\Web\ViewModel\ForecastPageViewModelFactory;
use App\Presentation\Web\ViewModel\ForecastTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForecastPageViewModelFactoryTest extends TestCase
{
    private const array ROW_LABELS = [
        '風速 (m/s)', '突風 (m/s)', '風向', '波高 (m)', '波向', '波周期 (秒)', 'うねり高さ (m)', 'うねり向き', 'うねり周期 (秒)',
    ];

    // 入力欄が表示中の地点と異なるときの案内（FR-021）。表示中の地点を含める
    private const string STALE_NOTICE = '入力欄の地点の予報はまだ表示していません。表示中の一覧は 北緯 27.75° / 東経 129.05° の予報です。「予報を表示」を押すと、入力欄の地点の予報に切り替わります。';

    private ForecastPageViewModelFactory $factory;
    private CoordinateQuery $query;

    protected function setUp(): void
    {
        $this->factory = new ForecastPageViewModelFactory();
        $this->query = new CoordinateQueryParser(new CoordinateNotationParser())->parse('27.75', '129.05');
    }

    public function testFreshForecastPage(): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::fresh($this->forecast()));

        self::assertSame([
            'latitude' => '27.75',
            'longitude' => '129.05',
            'latitudeError' => null,
            'longitudeError' => null,
            'staleNotice' => self::STALE_NOTICE,
        ], $viewModel->form);
        self::assertNull($viewModel->notice);
        self::assertSame('北緯 27.75° / 東経 129.05°', $viewModel->location);
        self::assertSame('最終更新：2026/10/05 20:15（21:15以降に再取得）', $viewModel->lastUpdated);
        self::assertSame([], $viewModel->groupMessages);
        self::assertNotNull($viewModel->table);
    }

    /**
     * @return iterable<string, array{float, float, string}>
     */
    public static function locationProvider(): iterable
    {
        yield 'north east' => [27.75, 129.05, '北緯 27.75° / 東経 129.05°'];
        yield 'south west' => [-33.5, -70.25, '南緯 33.50° / 西経 70.25°'];
        yield 'zero' => [0.0, 0.0, '北緯 0.00° / 東経 0.00°'];
    }

    #[DataProvider('locationProvider')]
    public function testLocation(float $latitude, float $longitude, string $expected): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::fresh($this->forecast(latitude: $latitude, longitude: $longitude)));

        self::assertSame($expected, $viewModel->location);
    }

    public function testLastUpdatedShowsDateWhenRefetchIsOnAnotherDay(): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::fresh($this->forecast(fetchedAt: '2026-10-05T23:30:00+09:00')));

        self::assertSame('最終更新：2026/10/05 23:30（10/06 00:30以降に再取得）', $viewModel->lastUpdated);
    }

    public function testColumns(): void
    {
        $columns = $this->table()->columns;

        self::assertCount(40, $columns);
        self::assertSame(['dateLabel' => '10/05(月)', 'timeLabel' => '20:00', 'startsNewDay' => false, 'startsThreeHourly' => false], $columns[0]);
        self::assertSame(['dateLabel' => null, 'timeLabel' => '21:00', 'startsNewDay' => false, 'startsThreeHourly' => false], $columns[1]);
        self::assertSame(['dateLabel' => '10/06(火)', 'timeLabel' => '00:00', 'startsNewDay' => true, 'startsThreeHourly' => false], $columns[4]);
        self::assertSame(['dateLabel' => null, 'timeLabel' => '06:00', 'startsNewDay' => false, 'startsThreeHourly' => false], $columns[10]);
        self::assertSame(['dateLabel' => null, 'timeLabel' => '21:00', 'startsNewDay' => false, 'startsThreeHourly' => true], $columns[24]);
        self::assertSame(['dateLabel' => '10/07(水)', 'timeLabel' => '00:00', 'startsNewDay' => true, 'startsThreeHourly' => false], $columns[25]);
        self::assertCount(1, array_filter($columns, static fn (array $column): bool => $column['startsThreeHourly']));
    }

    public function testRows(): void
    {
        $rows = $this->table()->rows;

        self::assertSame(self::ROW_LABELS, array_column($rows, 'label'));
        self::assertSame(['5.2', '8.4', '北北東', '1.3', '東', '6.3', '0.8', '南東', '9.1'], array_map(static fn (array $row): string => $row['cells'][0], $rows));
        foreach ($rows as $row) {
            self::assertCount(40, $row['cells']);
        }
    }

    public function testMissingValuesAreDashAndZeroIsNotMissing(): void
    {
        $hours = [
            new HourlyForecastView(new \DateTimeImmutable('2026-10-05T20:00:00+09:00'), null, null, null, null, null, null, null, null, null),
            new HourlyForecastView(new \DateTimeImmutable('2026-10-05T21:00:00+09:00'), 0.0, 0.0, 'N', 0.0, 'NNW', 0.0, 0.0, 'WSW', 0.0),
        ];

        $rows = $this->table($this->forecast(hours: $hours))->rows;

        self::assertSame(array_fill(0, 9, '—'), array_map(static fn (array $row): string => $row['cells'][0], $rows));
        self::assertSame(['0.0', '0.0', '北', '0.0', '北北西', '0.0', '0.0', '西南西', '0.0'], array_map(static fn (array $row): string => $row['cells'][1], $rows));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directionProvider(): iterable
    {
        $names = [
            'N' => '北', 'NNE' => '北北東', 'NE' => '北東', 'ENE' => '東北東', 'E' => '東', 'ESE' => '東南東', 'SE' => '南東', 'SSE' => '南南東',
            'S' => '南', 'SSW' => '南南西', 'SW' => '南西', 'WSW' => '西南西', 'W' => '西', 'WNW' => '西北西', 'NW' => '北西', 'NNW' => '北北西',
        ];
        foreach ($names as $identifier => $name) {
            yield $identifier => [$identifier, $name];
        }
    }

    #[DataProvider('directionProvider')]
    public function testDirectionNames(string $identifier, string $expected): void
    {
        $hours = [new HourlyForecastView(new \DateTimeImmutable('2026-10-05T20:00:00+09:00'), 1.0, 1.0, $identifier, 1.0, $identifier, 1.0, 1.0, $identifier, 1.0)];

        $rows = $this->table($this->forecast(hours: $hours))->rows;

        self::assertSame($expected, $rows[2]['cells'][0]);
        self::assertSame($expected, $rows[4]['cells'][0]);
        self::assertSame($expected, $rows[7]['cells'][0]);
    }

    public function testStaleForecastPage(): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::stale($this->forecast(fetchedAt: '2026-10-05T17:15:00+09:00')));

        self::assertSame(['type' => 'stale', 'message' => '最新の予報を取得できませんでした。表示中は 2026/10/05 17:15 時点の予報です'], $viewModel->notice);
        self::assertSame('最終更新：2026/10/05 17:15', $viewModel->lastUpdated);
        self::assertSame('北緯 27.75° / 東経 129.05°', $viewModel->location);
        self::assertNotNull($viewModel->table);
    }

    public function testUnavailablePage(): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::unavailable(27.75, 129.05));

        self::assertSame(['type' => 'unavailable', 'message' => '予報を取得できませんでした。時間をおいて再度お試しください'], $viewModel->notice);
        self::assertNull($viewModel->location);
        self::assertNull($viewModel->lastUpdated);
        self::assertNull($viewModel->table);
        self::assertSame('27.75', $viewModel->form['latitude']);
    }

    public function testRateLimitedPage(): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::rateLimited(27.75, 129.05));

        self::assertSame(['type' => 'rate_limited', 'message' => 'しばらく待ってから再度お試しください'], $viewModel->notice);
        self::assertNull($viewModel->location);
        self::assertNull($viewModel->lastUpdated);
        self::assertNull($viewModel->table);
    }

    /**
     * @return iterable<string, array{GroupAvailability, GroupAvailability, list<string>, list<string>}>
     */
    public static function groupProvider(): iterable
    {
        $wind = \array_slice(self::ROW_LABELS, 0, 3);
        $sea = \array_slice(self::ROW_LABELS, 3);

        yield 'sea not provided' => [GroupAvailability::Available, GroupAvailability::NotProvidedAtLocation, ['この地点では波・うねりの予報が得られません'], $wind];
        yield 'sea fetch failed' => [GroupAvailability::Available, GroupAvailability::FetchFailed, ['波・うねりの予報を取得できませんでした'], $wind];
        yield 'wind fetch failed' => [GroupAvailability::FetchFailed, GroupAvailability::Available, ['風の予報を取得できませんでした'], $sea];
        yield 'wind not provided' => [GroupAvailability::NotProvidedAtLocation, GroupAvailability::Available, ['この地点では風の予報が得られません'], $sea];
    }

    /**
     * @param list<string> $expectedMessages
     * @param list<string> $expectedRows
     */
    #[DataProvider('groupProvider')]
    public function testUnavailableGroupsAreReplacedByMessages(GroupAvailability $wind, GroupAvailability $sea, array $expectedMessages, array $expectedRows): void
    {
        $viewModel = $this->factory->create($this->query, MarineForecastResult::fresh($this->forecast(wind: $wind, sea: $sea)));

        self::assertSame($expectedMessages, $viewModel->groupMessages);
        self::assertNotNull($viewModel->table);
        self::assertSame($expectedRows, array_column($viewModel->table->rows, 'label'));
    }

    /**
     * @return iterable<string, array{MarineForecastResult, string, string, string}>
     */
    public static function favoriteTargetProvider(): iterable
    {
        $forecast = static fn (float $latitude, float $longitude): MarineForecastView => new MarineForecastView(
            $latitude,
            $longitude,
            new \DateTimeImmutable('2026-10-05T20:15:00+09:00'),
            new \DateTimeImmutable('2026-10-05T21:15:00+09:00'),
            GroupAvailability::Available,
            GroupAvailability::Available,
            [],
        );

        // [結果, 緯度, 経度, 保存される地点の表示]
        yield 'fresh' => [MarineForecastResult::fresh($forecast(27.75, 129.05)), '27.75', '129.05', '北緯 27.75° / 東経 129.05°'];
        yield 'stale' => [MarineForecastResult::stale($forecast(27.75, 129.05)), '27.75', '129.05', '北緯 27.75° / 東経 129.05°'];
        // 予報が得られなくても、保存される地点は結果の緯度・経度から作る
        yield 'unavailable' => [MarineForecastResult::unavailable(27.75, 129.05), '27.75', '129.05', '北緯 27.75° / 東経 129.05°'];
        yield 'rate limited' => [MarineForecastResult::rateLimited(27.75, 129.05), '27.75', '129.05', '北緯 27.75° / 東経 129.05°'];
        yield 'pads decimals' => [MarineForecastResult::unavailable(28.1, 129.3), '28.10', '129.30', '北緯 28.10° / 東経 129.30°'];
        yield 'negative' => [MarineForecastResult::unavailable(-0.5, -120.0), '-0.50', '-120.00', '南緯 0.50° / 西経 120.00°'];
        // 丸めた結果が -0.0 になる座標を「-0.00」と表示しない
        yield 'negative zero' => [MarineForecastResult::unavailable(-0.0, 129.05), '0.00', '129.05', '北緯 0.00° / 東経 129.05°'];
    }

    #[DataProvider('favoriteTargetProvider')]
    public function testFavoriteTarget(MarineForecastResult $result, string $latitude, string $longitude, string $label): void
    {
        $viewModel = $this->factory->create($this->query, $result);

        self::assertSame(['latitude' => $latitude, 'longitude' => $longitude, 'label' => $label], $viewModel->favoriteTarget);
    }

    /**
     * @return iterable<string, array{MarineForecastResult|null, bool}>
     */
    public static function staleNoticeProvider(): iterable
    {
        $forecast = static fn (): MarineForecastView => new MarineForecastView(
            27.75,
            129.05,
            new \DateTimeImmutable('2026-10-05T20:15:00+09:00'),
            new \DateTimeImmutable('2026-10-05T21:15:00+09:00'),
            GroupAvailability::Available,
            GroupAvailability::Available,
            [],
        );

        yield 'fresh' => [MarineForecastResult::fresh($forecast()), true];
        yield 'stale' => [MarineForecastResult::stale($forecast()), true];
        // 食い違いで誤解する一覧がない画面には出さない
        yield 'unavailable' => [MarineForecastResult::unavailable(27.75, 129.05), false];
        yield 'rate limited' => [MarineForecastResult::rateLimited(27.75, 129.05), false];
        yield 'invalid input' => [null, false];
    }

    #[DataProvider('staleNoticeProvider')]
    public function testStaleNoticeIsOnlyForPagesWithAForecastList(?MarineForecastResult $result, bool $expected): void
    {
        $viewModel = null === $result
            ? $this->factory->createForInvalidInput(new CoordinateQueryParser(new CoordinateNotationParser())->parse('95', '129.05'))
            : $this->factory->create($this->query, $result);

        self::assertSame($expected ? self::STALE_NOTICE : null, $viewModel->form['staleNotice']);
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function inputNoticeProvider(): iterable
    {
        yield 'longitude ignored' => ['lon', 'この地点は緯度欄の「緯度, 経度」から読み取りました。経度欄に入っていた値は使っていません'];
        yield 'latitude ignored' => ['lat', 'この地点は経度欄の「緯度, 経度」から読み取りました。緯度欄に入っていた値は使っていません'];
        yield 'nothing ignored' => [null, null];
    }

    #[DataProvider('inputNoticeProvider')]
    public function testInputNotice(?string $ignored, ?string $expected): void
    {
        $query = new CoordinateQueryParser(new CoordinateNotationParser())->parse('35.10', '139.20', $ignored);

        $viewModel = $this->factory->create($query, MarineForecastResult::fresh($this->forecast()));

        self::assertSame($expected, $viewModel->inputNotice);
    }

    public function testInvalidInputHasNoInputNotice(): void
    {
        $query = new CoordinateQueryParser(new CoordinateNotationParser())->parse('95', 'abc', 'lon');

        self::assertNull($this->factory->createForInvalidInput($query)->inputNotice);
    }

    public function testInvalidInputPage(): void
    {
        $query = new CoordinateQueryParser(new CoordinateNotationParser())->parse('95', 'abc');

        $viewModel = $this->factory->createForInvalidInput($query);

        self::assertSame([
            'latitude' => '95',
            'longitude' => 'abc',
            'latitudeError' => '緯度は -90〜90 の範囲で入力してください',
            'longitudeError' => '経度を読み取れませんでした。入力例：27.75 / 27.75, 129.05 / 27°45.0\'N / 27°45\'00"N',
            'staleNotice' => null,
        ], $viewModel->form);
        self::assertNull($viewModel->notice);
        self::assertNull($viewModel->location);
        self::assertNull($viewModel->lastUpdated);
        self::assertSame([], $viewModel->groupMessages);
        self::assertNull($viewModel->table);
        self::assertNull($viewModel->favoriteTarget);
    }

    private function table(?MarineForecastView $forecast = null): ForecastTable
    {
        $table = $this->factory->create($this->query, MarineForecastResult::fresh($forecast ?? $this->forecast()))->table;
        self::assertNotNull($table);

        return $table;
    }

    /**
     * @param list<HourlyForecastView>|null $hours
     */
    private function forecast(
        float $latitude = 27.75,
        float $longitude = 129.05,
        string $fetchedAt = '2026-10-05T20:15:00+09:00',
        GroupAvailability $wind = GroupAvailability::Available,
        GroupAvailability $sea = GroupAvailability::Available,
        ?array $hours = null,
    ): MarineForecastView {
        $fetched = new \DateTimeImmutable($fetchedAt);

        return new MarineForecastView($latitude, $longitude, $fetched, $fetched->modify('+1 hour'), $wind, $sea, $hours ?? self::defaultHours());
    }

    /**
     * 10/05 20:00 から 24 時間は 1 時間ごと、その先 10/08 18:00 までは 3 時間ごと（ForecastTimeline と同じ並び）.
     *
     * @return list<HourlyForecastView>
     */
    private static function defaultHours(): array
    {
        $start = new \DateTimeImmutable('2026-10-05T20:00:00+09:00');
        $times = [];
        for ($i = 0; $i < 24; ++$i) {
            $times[] = $start->modify(\sprintf('+%d hours', $i));
        }
        for ($i = 25; $i <= 70; $i += 3) {
            $times[] = $start->modify(\sprintf('+%d hours', $i));
        }

        return array_map(
            static fn (\DateTimeImmutable $time): HourlyForecastView => new HourlyForecastView($time, 5.2, 8.4, 'NNE', 1.25, 'E', 6.3, 0.8, 'SE', 9.1),
            $times,
        );
    }
}
