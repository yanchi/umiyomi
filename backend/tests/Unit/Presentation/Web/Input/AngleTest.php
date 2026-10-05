<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Input;

use App\Presentation\Web\Input\Angle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AngleTest extends TestCase
{
    /**
     * @return iterable<string, array{int, float, string}>
     */
    public static function valueProvider(): iterable
    {
        yield 'positive' => [2776, 27.76, '27.76'];
        yield 'negative' => [-2776, -27.76, '-27.76'];
        yield 'zero' => [0, 0.0, '0.00'];
        yield 'small negative' => [-505, -5.05, '-5.05'];
        yield 'three digit degrees' => [12905, 129.05, '129.05'];
        yield 'one hundredth' => [1, 0.01, '0.01'];
    }

    #[DataProvider('valueProvider')]
    public function testConvertsHundredths(int $hundredths, float $float, string $canonical): void
    {
        $angle = new Angle($hundredths, null, 0, false);

        self::assertSame($float, $angle->toFloat());
        self::assertSame($canonical, $angle->toCanonicalString());
    }

    public function testCanonicalStringHasNoNegativeZero(): void
    {
        // 「-0.004」のように符号付きで入力され、丸めた結果が 0 になる値
        $angle = new Angle(-0, null, 0, true);

        self::assertSame('0.00', $angle->toCanonicalString());
        self::assertSame(0.0, $angle->toFloat());
    }

    /**
     * @return iterable<string, array{int, bool, int, bool}>
     */
    public static function rangeProvider(): iterable
    {
        // [wholeDegrees, hasFraction, limit, 範囲内か]
        yield '89.x within 90' => [89, true, 90, true];
        yield '90 exactly within 90' => [90, false, 90, true];
        yield '90.004 outside 90' => [90, true, 90, false];
        yield '91 outside 90' => [91, false, 90, false];
        yield '179.x within 180' => [179, true, 180, true];
        yield '180 exactly within 180' => [180, false, 180, true];
        yield '180.001 outside 180' => [180, true, 180, false];
        yield '181 outside 180' => [181, false, 180, false];
        yield 'capped digits outside' => [999, false, 180, false];
    }

    #[DataProvider('rangeProvider')]
    public function testIsWithinUsesValueBeforeRounding(int $wholeDegrees, bool $hasFraction, int $limit, bool $expected): void
    {
        $angle = new Angle($wholeDegrees * 100, null, $wholeDegrees, $hasFraction);

        self::assertSame($expected, $angle->isWithin($limit));
    }
}
