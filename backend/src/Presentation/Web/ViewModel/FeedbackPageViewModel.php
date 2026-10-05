<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

/**
 * 案内画面の Twig に渡す唯一のオブジェクト.
 */
final readonly class FeedbackPageViewModel
{
    /**
     * @param string                                          $formUrl        事前入力付きの外部フォームの URL
     * @param string|null                                     $prefillSummary 予報の地点から来たときの「…がフォームに入ります」
     * @param array{latitude: string, longitude: string}|null $quotedInputs   受け付けなかった入力から来たときの、引用して表示する文字列
     * @param array<string, string>                           $backParameters
     */
    public function __construct(
        public string $formUrl,
        public ?string $prefillSummary,
        public ?array $quotedInputs,
        public string $backRoute,
        public array $backParameters,
        public string $backLabel,
    ) {
    }
}
