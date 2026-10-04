<?php

declare(strict_types=1);

namespace App\Application\Marine\UseCase;

final readonly class ViewMarineForecastInput
{
    /**
     * @param float  $latitude  Presentation で検証済みの値
     * @param float  $longitude Presentation で検証済みの値
     * @param string $clientKey 提供元への問い合わせ回数を数える単位（接続元 IP）。HTTP の Request を UseCase に渡さないため文字列で受け取る
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $clientKey,
    ) {
    }
}
