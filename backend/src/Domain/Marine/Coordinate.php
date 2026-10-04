<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 同一地点の判定・提供元への問い合わせ・表示のすべてで同じ値を使うため、生成時に小数点以下 2 桁へ丸めて保持する.
 */
final readonly class Coordinate
{
    private float $latitude;
    private float $longitude;

    public function __construct(float $latitude, float $longitude)
    {
        // 丸める前の値で検証する。90.004 のような値を丸めで範囲内に入れてしまわないため
        if (!($latitude >= -90.0 && $latitude <= 90.0)) {
            throw new \InvalidArgumentException(\sprintf('Latitude must be between -90 and 90, got %s.', $latitude));
        }
        if (!($longitude >= -180.0 && $longitude <= 180.0)) {
            throw new \InvalidArgumentException(\sprintf('Longitude must be between -180 and 180, got %s.', $longitude));
        }

        // + 0.0 で -0.0 を 0.0 にそろえ、キーが "-0.00" にならないようにする
        $this->latitude = round($latitude, 2) + 0.0;
        $this->longitude = round($longitude, 2) + 0.0;
    }

    public function latitude(): float
    {
        return $this->latitude;
    }

    public function longitude(): float
    {
        return $this->longitude;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    public function key(): string
    {
        return \sprintf('%.2f_%.2f', $this->latitude, $this->longitude);
    }
}
