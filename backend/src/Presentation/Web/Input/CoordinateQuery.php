<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

final readonly class CoordinateQuery
{
    /**
     * @param string                                $rawLatitude        入力欄に戻す、正規化前の文字列
     * @param string                                $rawLongitude       入力欄に戻す、正規化前の文字列
     * @param float|null                            $latitude           検証を通った値（小数点以下 2 桁に丸め済み）。エラーなら null
     * @param float|null                            $longitude          検証を通った値（小数点以下 2 桁に丸め済み）。エラーなら null
     * @param array<'latitude'|'longitude', string> $errors             項目ごとのエラーメッセージ
     * @param string|null                           $canonicalLatitude  正規形（27.75）の緯度。エラーなら null
     * @param string|null                           $canonicalLongitude 正規形（129.05）の経度。エラーなら null
     * @param 'latitude'|'longitude'|null           $ignoredField       1 行の「緯度, 経度」を使い、値を使わなかった欄
     */
    public function __construct(
        public string $rawLatitude,
        public string $rawLongitude,
        public ?float $latitude,
        public ?float $longitude,
        public array $errors,
        public ?string $canonicalLatitude = null,
        public ?string $canonicalLongitude = null,
        public ?string $ignoredField = null,
    ) {
    }

    /**
     * @phpstan-assert-if-true !null $this->latitude
     * @phpstan-assert-if-true !null $this->longitude
     * @phpstan-assert-if-true !null $this->canonicalLatitude
     * @phpstan-assert-if-true !null $this->canonicalLongitude
     */
    public function isValid(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    /**
     * 入力欄の文字列が正規形でないとき、URL・入力欄・表示中の地点を十進数にそろえるために、予報を取得せずリダイレクトする（FR-010）.
     */
    public function needsRedirect(): bool
    {
        return $this->isValid()
            && ($this->rawLatitude !== $this->canonicalLatitude || $this->rawLongitude !== $this->canonicalLongitude);
    }
}
