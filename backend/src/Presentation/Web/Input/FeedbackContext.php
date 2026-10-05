<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 案内画面へ来た元の画面の状態.
 *
 * 種類と値の組み合わせが食い違った状態を作れないよう、コンストラクタを閉じて名前付きコンストラクタだけで作る
 */
final readonly class FeedbackContext
{
    private function __construct(
        public FeedbackContextType $type,
        public ?string $latitude = null,
        public ?string $longitude = null,
        public ?string $lastUpdated = null,
        public ?string $inputLatitude = null,
        public ?string $inputLongitude = null,
    ) {
    }

    public static function none(): self
    {
        return new self(FeedbackContextType::None);
    }

    public static function forecast(string $latitude, string $longitude, ?string $lastUpdated): self
    {
        return new self(FeedbackContextType::Forecast, $latitude, $longitude, $lastUpdated);
    }

    public static function rejectedInput(string $inputLatitude, string $inputLongitude): self
    {
        return new self(FeedbackContextType::RejectedInput, inputLatitude: $inputLatitude, inputLongitude: $inputLongitude);
    }
}
