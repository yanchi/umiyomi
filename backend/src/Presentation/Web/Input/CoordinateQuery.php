<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

final readonly class CoordinateQuery
{
    /**
     * @param string                                $rawLatitude  入力欄に戻す、正規化前の文字列
     * @param string                                $rawLongitude 入力欄に戻す、正規化前の文字列
     * @param float|null                            $latitude     検証を通った値。エラーなら null
     * @param float|null                            $longitude    検証を通った値。エラーなら null
     * @param array<'latitude'|'longitude', string> $errors       項目ごとのエラーメッセージ
     */
    public function __construct(
        public string $rawLatitude,
        public string $rawLongitude,
        public ?float $latitude,
        public ?float $longitude,
        public array $errors,
    ) {
    }

    /**
     * @phpstan-assert-if-true !null $this->latitude
     * @phpstan-assert-if-true !null $this->longitude
     */
    public function isValid(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
