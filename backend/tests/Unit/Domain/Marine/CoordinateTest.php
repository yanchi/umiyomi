<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Marine;

use App\Domain\Marine\Coordinate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinateTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float}>
     */
    public static function boundaryProvider(): iterable
    {
        yield 'north pole / east edge' => [90.0, 180.0];
        yield 'south pole / west edge' => [-90.0, -180.0];
        yield 'origin' => [0.0, 0.0];
    }

    #[DataProvider('boundaryProvider')]
    public function testAcceptsBoundaryValues(float $latitude, float $longitude): void
    {
        $coordinate = new Coordinate($latitude, $longitude);

        self::assertSame($latitude, $coordinate->latitude());
        self::assertSame($longitude, $coordinate->longitude());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function outOfRangeProvider(): iterable
    {
        yield 'latitude above 90' => [90.01, 0.0];
        yield 'latitude below -90' => [-90.01, 0.0];
        yield 'longitude above 180' => [0.0, 180.01];
        yield 'longitude below -180' => [0.0, -180.01];
        yield 'latitude not finite' => [NAN, 0.0];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testRejectsOutOfRange(float $latitude, float $longitude): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinate($latitude, $longitude);
    }

    /**
     * @return iterable<string, array{float, float, float, float}>
     */
    public static function roundingProvider(): iterable
    {
        yield 'many decimals' => [27.123456789, 129.987654321, 27.12, 129.99];
        yield 'half rounds up' => [27.755, 129.045, 27.76, 129.05];
        yield 'negative' => [-27.755, -120.004, -27.76, -120.0];
    }

    #[DataProvider('roundingProvider')]
    public function testRoundsToTwoDecimals(float $latitude, float $longitude, float $expectedLatitude, float $expectedLongitude): void
    {
        $coordinate = new Coordinate($latitude, $longitude);

        self::assertSame($expectedLatitude, $coordinate->latitude());
        self::assertSame($expectedLongitude, $coordinate->longitude());
    }

    public function testEqualsComparesRoundedValues(): void
    {
        $coordinate = new Coordinate(27.75, 129.05);

        self::assertTrue($coordinate->equals(new Coordinate(27.7500, 129.0501)));
        self::assertFalse($coordinate->equals(new Coordinate(27.76, 129.05)));
    }

    /**
     * @return iterable<string, array{float, float, string}>
     */
    public static function keyProvider(): iterable
    {
        yield 'positive' => [27.75, 129.05, '27.75_129.05'];
        yield 'negative with padding' => [-0.5, -120.0, '-0.50_-120.00'];
        yield 'negative zero is normalized' => [-0.001, -0.004, '0.00_0.00'];
    }

    #[DataProvider('keyProvider')]
    public function testKey(float $latitude, float $longitude, string $expected): void
    {
        self::assertSame($expected, new Coordinate($latitude, $longitude)->key());
    }
}
