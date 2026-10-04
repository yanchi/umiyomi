<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

/**
 * 行＝項目、列＝時刻の一覧。Twig 側で計算・分岐を増やさないよう、表示用に整形済みの文字列だけを持つ.
 *
 * @phpstan-type Column array{dateLabel: string|null, timeLabel: string, startsNewDay: bool, startsThreeHourly: bool}
 * @phpstan-type Row array{label: string, cells: list<string>}
 */
final readonly class ForecastTable
{
    /**
     * @param list<Column> $columns
     * @param list<Row>    $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }
}
