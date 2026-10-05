<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 読み取り済みの 1 つの値。丸める前の正確な値から求めた整数で持ち、浮動小数点を通さない（research R2）.
 */
final readonly class Angle
{
    /**
     * @param int       $hundredths   符号付き。小数点以下 2 桁に丸めた値 × 100（南緯 27.76 は -2776）
     * @param Axis|null $axis         方角の文字から決まる軸。方角の文字がなければ null
     * @param int       $wholeDegrees 丸める前の整数部（度）の大きさ。4 桁以上は 999 に寄せて桁あふれを防ぐ
     * @param bool      $hasFraction  整数部より下の桁（小数部・分・秒）に 0 以外の数字があるか
     */
    public function __construct(
        public int $hundredths,
        public ?Axis $axis,
        public int $wholeDegrees,
        public bool $hasFraction,
    ) {
    }

    /**
     * 範囲は丸める前の値で判定する（90.004 を丸めで範囲内に入れない）ため、hundredths ではなく整数部と小数の有無で見る.
     */
    public function isWithin(int $limit): bool
    {
        return $this->wholeDegrees < $limit || ($this->wholeDegrees === $limit && !$this->hasFraction);
    }

    public function toFloat(): float
    {
        return $this->hundredths / 100.0;
    }

    public function toCanonicalString(): string
    {
        // 整数で組み立てるので、丸めた結果が 0 の値に符号が付く「-0.00」は出ない
        $magnitude = abs($this->hundredths);

        return \sprintf('%s%d.%02d', $this->hundredths < 0 ? '-' : '', intdiv($magnitude, 100), $magnitude % 100);
    }
}
