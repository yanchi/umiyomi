<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Marine;

use App\Domain\Marine\CompassPoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompassPointTest extends TestCase
{
    /**
     * @return iterable<string, array{float, CompassPoint}>
     */
    public static function centerProvider(): iterable
    {
        foreach (CompassPoint::cases() as $index => $point) {
            yield $point->value.' center' => [$index * 22.5, $point];
        }
    }

    #[DataProvider('centerProvider')]
    public function testCenterValues(float $degrees, CompassPoint $expected): void
    {
        self::assertSame($expected, CompassPoint::fromDegrees($degrees));
    }

    /**
     * @return iterable<string, array{float, CompassPoint}>
     */
    public static function boundaryProvider(): iterable
    {
        // 境界値は時計回り側の方位に含める
        foreach (CompassPoint::cases() as $index => $point) {
            $boundary = $index * 22.5 + 11.25;
            $next = CompassPoint::cases()[($index + 1) % 16];
            yield $point->value.' upper boundary' => [$boundary, $next];
            yield $point->value.' just below upper boundary' => [$boundary - 0.01, $point];
        }

        yield '0' => [0.0, CompassPoint::N];
        yield '11.24' => [11.24, CompassPoint::N];
        yield '11.25' => [11.25, CompassPoint::NNE];
        yield '22.5' => [22.5, CompassPoint::NNE];
        yield '348.75' => [348.75, CompassPoint::N];
        yield '348.74' => [348.74, CompassPoint::NNW];
        yield '360' => [360.0, CompassPoint::N];
        yield '-11.25' => [-11.25, CompassPoint::N];
        yield '-22.5' => [-22.5, CompassPoint::NNW];
        yield '720' => [720.0, CompassPoint::N];
        yield '450' => [450.0, CompassPoint::E];
    }

    #[DataProvider('boundaryProvider')]
    public function testBoundaryValues(float $degrees, CompassPoint $expected): void
    {
        self::assertSame($expected, CompassPoint::fromDegrees($degrees));
    }
}
