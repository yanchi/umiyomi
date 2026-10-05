<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

use App\Presentation\Web\Feedback\FeedbackFormLink;
use App\Presentation\Web\Input\FeedbackContext;
use App\Presentation\Web\Input\FeedbackContextType;

final readonly class FeedbackPageViewModelFactory
{
    public function __construct(private FeedbackFormLink $formLink)
    {
    }

    public function create(FeedbackContext $context): FeedbackPageViewModel
    {
        // 戻り先は、予報画面の URL が lat・lon だけで決まることを使う。JavaScript や Referer に頼らず同じ画面へ戻せる
        [$backRoute, $backParameters, $backLabel] = match ($context->type) {
            FeedbackContextType::None => ['app_home', [], 'トップ画面に戻る'],
            FeedbackContextType::Forecast => ['app_forecast', ['lat' => (string) $context->latitude, 'lon' => (string) $context->longitude], '予報画面に戻る'],
            FeedbackContextType::RejectedInput => ['app_forecast', ['lat' => (string) $context->inputLatitude, 'lon' => (string) $context->inputLongitude], '入力画面に戻る'],
        };

        // 外部フォームへ渡すのは地点・最終更新・入力した文字列を 1 行にした文章だけ。お気に入りなどほかの情報は含めない
        $prefill = $this->prefill($context);

        return new FeedbackPageViewModel(
            formUrl: $this->formLink->urlFor($prefill),
            prefillSummary: FeedbackContextType::Forecast === $context->type ? $prefill.' がフォームに入ります。' : null,
            quotedInputs: FeedbackContextType::RejectedInput === $context->type ? [
                'latitude' => self::orEmptyLabel((string) $context->inputLatitude),
                'longitude' => self::orEmptyLabel((string) $context->inputLongitude),
            ] : null,
            backRoute: $backRoute,
            backParameters: $backParameters,
            backLabel: $backLabel,
        );
    }

    private function prefill(FeedbackContext $context): ?string
    {
        return match ($context->type) {
            FeedbackContextType::None => null,
            FeedbackContextType::Forecast => \sprintf('緯度 %s・経度 %s', $context->latitude, $context->longitude)
                .(null === $context->lastUpdated ? '' : \sprintf('、最終更新 %s', $context->lastUpdated)),
            FeedbackContextType::RejectedInput => \sprintf(
                '受け付けられなかった入力：緯度欄「%s」、経度欄「%s」',
                self::orEmptyLabel((string) $context->inputLatitude),
                self::orEmptyLabel((string) $context->inputLongitude),
            ),
        };
    }

    private static function orEmptyLabel(string $value): string
    {
        return '' === $value ? '（空）' : $value;
    }
}
