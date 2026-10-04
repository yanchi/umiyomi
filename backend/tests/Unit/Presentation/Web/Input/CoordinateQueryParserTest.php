<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Input;

use App\Presentation\Web\Input\CoordinateQueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinateQueryParserTest extends TestCase
{
    private const string LATITUDE_FORMAT = '緯度を数値（-90〜90）で入力してください';
    private const string LONGITUDE_FORMAT = '経度を数値（-180〜180）で入力してください';
    private const string LATITUDE_RANGE = '緯度は -90〜90 の範囲で入力してください';
    private const string LONGITUDE_RANGE = '経度は -180〜180 の範囲で入力してください';
    private const string DECIMAL_HINT = '十進数（例：27.75）で入力してください';

    private CoordinateQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CoordinateQueryParser();
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'plain' => ['27.75', 27.75];
        yield 'negative' => ['-27.75', -27.75];
        yield 'explicit plus' => ['+27.75', 27.75];
        yield 'leading dot' => ['.5', 0.5];
        yield 'trailing dot' => ['27.', 27.0];
        yield 'integer' => ['27', 27.0];
        yield 'surrounding spaces' => [' 27.75 ', 27.75];
        yield 'surrounding full-width spaces' => ["\u{3000}27.75\u{3000}", 27.75];
        yield 'full-width digits and period' => ['２７．７５', 27.75];
        yield 'full-width minus' => ['－２７．７５', -27.75];
        yield 'minus sign' => ['−27.75', -27.75];
        yield 'full-width plus' => ['＋27.75', 27.75];
        yield 'upper boundary' => ['90', 90.0];
        yield 'lower boundary' => ['-90', -90.0];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsLatitude(string $input, float $expected): void
    {
        $query = $this->parser->parse($input, '129.05');

        self::assertTrue($query->isValid());
        self::assertSame($expected, $query->latitude);
        self::assertSame(129.05, $query->longitude);
        self::assertSame([], $query->errors);
    }

    public function testAcceptsLongitudeBoundaries(): void
    {
        self::assertSame(180.0, $this->parser->parse('0', '180')->longitude);
        self::assertSame(-180.0, $this->parser->parse('0', '-180')->longitude);
    }

    public function testRejectsOutOfRange(): void
    {
        $query = $this->parser->parse('90.01', '-180.01');

        self::assertFalse($query->isValid());
        self::assertNull($query->latitude);
        self::assertNull($query->longitude);
        self::assertSame(['latitude' => self::LATITUDE_RANGE, 'longitude' => self::LONGITUDE_RANGE], $query->errors);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'only spaces' => ['  '];
        yield 'letters' => ['abc'];
        yield 'comma decimal' => ['27,75'];
        yield 'exponent' => ['1e1'];
        yield 'two dots' => ['27.7.5'];
        yield 'sign only' => ['-'];
    }

    #[DataProvider('malformedProvider')]
    public function testRejectsMalformedLongitude(?string $input): void
    {
        $query = $this->parser->parse('27.75', $input);

        self::assertFalse($query->isValid());
        self::assertSame(27.75, $query->latitude);
        self::assertNull($query->longitude);
        self::assertSame(['longitude' => self::LONGITUDE_FORMAT], $query->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function degreeNotationProvider(): iterable
    {
        yield 'hemisphere letter' => ['N27.75'];
        yield 'degrees and minutes' => ["27°45'"];
        yield 'kanji degrees' => ['27度45分'];
    }

    #[DataProvider('degreeNotationProvider')]
    public function testAddsDecimalHintForDegreeNotation(string $input): void
    {
        $query = $this->parser->parse($input, '129.05');

        self::assertSame(['latitude' => self::LATITUDE_FORMAT.'。'.self::DECIMAL_HINT], $query->errors);
    }

    public function testReportsBothFieldsIndependently(): void
    {
        $query = $this->parser->parse('', 'abc');

        self::assertSame(['latitude' => self::LATITUDE_FORMAT, 'longitude' => self::LONGITUDE_FORMAT], $query->errors);
    }

    public function testKeepsRawInput(): void
    {
        $query = $this->parser->parse(' ２７．７５ ', 'abc');

        self::assertSame(' ２７．７５ ', $query->rawLatitude);
        self::assertSame('abc', $query->rawLongitude);

        $missing = $this->parser->parse(null, null);
        self::assertSame('', $missing->rawLatitude);
        self::assertSame('', $missing->rawLongitude);
    }
}
