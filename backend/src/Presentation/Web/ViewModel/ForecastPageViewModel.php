<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

/**
 * 予報ページの Twig に渡す唯一のオブジェクト.
 *
 * @phpstan-type Form array{latitude: string, longitude: string, latitudeError: string|null, longitudeError: string|null, staleNotice: string|null}
 * @phpstan-type Notice array{type: 'stale'|'unavailable'|'rate_limited', message: string}
 * @phpstan-type FavoriteTarget array{latitude: string, longitude: string, label: string}
 */
final readonly class ForecastPageViewModel
{
    /**
     * @param Form                                                             $form
     * @param Notice|null                                                      $notice
     * @param list<string>                                                     $groupMessages
     * @param FavoriteTarget|null                                              $favoriteTarget  お気に入りに保存できる表示中の地点（丸め済み）。入力エラーのときは null
     * @param array<string, string>                                            $feedbackQuery   フッターのフィードバックのリンクに付ける Query
     * @param string|null                                                      $inputNotice     1 行の「緯度, 経度」から読み取り、もう一方の欄の値を使わなかったときの通知（FR-004）
     * @param 'forecast'|'forecast_unavailable'|'rate_limited'|'invalid_input' $analyticsScreen アクセス解析に送る画面の種類
     * @param array{lat: string, lon: string}|array{}                          $analyticsQuery  アクセス解析に送ってよい値だけ。入力不正のときは空（FR-005）
     */
    public function __construct(
        public array $form,
        public ?array $notice,
        public ?string $location,
        public ?string $lastUpdated,
        public array $groupMessages,
        public ?ForecastTable $table,
        public ?array $favoriteTarget,
        public ?string $inputNotice = null,
        public array $feedbackQuery = [],
        public string $analyticsScreen = 'invalid_input',
        public array $analyticsQuery = [],
    ) {
    }
}
