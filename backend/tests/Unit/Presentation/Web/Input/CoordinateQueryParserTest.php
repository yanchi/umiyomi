<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Input;

use App\Presentation\Web\Input\CoordinateNotationParser;
use App\Presentation\Web\Input\CoordinateQueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinateQueryParserTest extends TestCase
{
    private const string EXAMPLES = '入力例：27.75 / 27.75, 129.05 / 27°45.0\'N / 27°45\'00"N';
    private const string LATITUDE_UNREADABLE = '緯度を読み取れませんでした。'.self::EXAMPLES;
    private const string LONGITUDE_UNREADABLE = '経度を読み取れませんでした。'.self::EXAMPLES;
    private const string LATITUDE_RANGE = '緯度は -90〜90 の範囲で入力してください';
    private const string LONGITUDE_RANGE = '経度は -180〜180 の範囲で入力してください';

    private CoordinateQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CoordinateQueryParser(new CoordinateNotationParser());
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
        yield 'rounded half up' => ['27.755', 27.76];
        yield 'north' => ['N27.75', 27.75];
        yield 'south' => ['27.75S', -27.75];
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
        self::assertSame(-129.05, $this->parser->parse('0', '129.05W')->longitude);
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
     * @return iterable<string, array{string, bool}>
     */
    public static function rangeBeforeRoundingProvider(): iterable
    {
        // 範囲は丸める前の値で判定する。90.004 は丸めると 90.00 になるが範囲外
        yield '90 is valid' => ['90', true];
        yield '90.004 is out of range' => ['90.004', false];
        yield '90.000 is valid' => ['90.000', true];
        yield '-90.004 is out of range' => ['-90.004', false];
    }

    #[DataProvider('rangeBeforeRoundingProvider')]
    public function testRangeIsCheckedBeforeRounding(string $latitude, bool $valid): void
    {
        $query = $this->parser->parse($latitude, '129.05');

        self::assertSame($valid, $query->isValid());
        self::assertSame($valid ? [] : ['latitude' => self::LATITUDE_RANGE], $query->errors);
    }

    public function testLongitudeRangeIsCheckedBeforeRounding(): void
    {
        self::assertTrue($this->parser->parse('27.75', '-180')->isValid());
        self::assertSame(['longitude' => self::LONGITUDE_RANGE], $this->parser->parse('27.75', '180.001')->errors);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unreadableProvider(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'two dots' => ['27.7.5'];
        yield 'exponent' => ['1e1'];
        yield 'sign only' => ['-'];
    }

    #[DataProvider('unreadableProvider')]
    public function testUnreadableLongitudeShowsExamples(string $input): void
    {
        $query = $this->parser->parse('27.75', $input);

        self::assertFalse($query->isValid());
        self::assertNull($query->longitude);
        self::assertSame(['longitude' => self::LONGITUDE_UNREADABLE], $query->errors);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptyProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'only spaces' => ['  '];
    }

    #[DataProvider('emptyProvider')]
    public function testEmptyLongitude(?string $input): void
    {
        $query = $this->parser->parse('27.75', $input);

        self::assertSame(['longitude' => '経度を入力してください'], $query->errors);
    }

    public function testReportsBothFieldsIndependently(): void
    {
        $query = $this->parser->parse('', 'abc');

        self::assertSame(['latitude' => '緯度を入力してください', 'longitude' => self::LONGITUDE_UNREADABLE], $query->errors);
    }

    public function testUnreadableLatitudeShowsExamples(): void
    {
        $query = $this->parser->parse('北緯二十七度', '129.05');

        self::assertSame(['latitude' => self::LATITUDE_UNREADABLE], $query->errors);
    }

    public function testAxisMismatch(): void
    {
        self::assertSame(
            ['latitude' => '緯度には N（北緯）か S（南緯）を付けてください。E・W は経度に使います'],
            $this->parser->parse('27.75E', '129.05')->errors,
        );
        self::assertSame(
            ['longitude' => '経度には E（東経）か W（西経）を付けてください。N・S は緯度に使います'],
            $this->parser->parse('27.75', '129.05N')->errors,
        );
    }

    public function testDirectionErrors(): void
    {
        self::assertSame(
            ['latitude' => '緯度にマイナス記号と方角の文字の両方があります。どちらか一方にしてください'],
            $this->parser->parse('-27.75N', '129.05')->errors,
        );
        self::assertSame(
            ['longitude' => '経度に方角の文字が複数あります。N・S・E・W のどれか 1 つにしてください'],
            $this->parser->parse('27.75', 'E129.05W')->errors,
        );
    }

    public function testTooLong(): void
    {
        $query = $this->parser->parse(str_repeat('1', 101), '129.05');

        self::assertSame(['latitude' => '緯度の入力が長すぎます。100 文字以内で入力してください'], $query->errors);
    }

    /**
     * @return iterable<string, array{string, string, bool, string, string}>
     */
    public static function canonicalProvider(): iterable
    {
        // [緯度欄, 経度欄, リダイレクトが必要か, 正規形の緯度, 正規形の経度]
        yield 'already canonical' => ['27.75', '129.05', false, '27.75', '129.05'];
        yield 'zero canonical' => ['0.00', '0.00', false, '0.00', '0.00'];
        yield 'trailing zeros' => ['27.7500', '129.05', true, '27.75', '129.05'];
        yield 'rounded' => ['27.755', '129.05', true, '27.76', '129.05'];
        yield 'full-width' => ['２７．７５', '129.05', true, '27.75', '129.05'];
        yield 'leading space' => [' 27.75', '129.05', true, '27.75', '129.05'];
        yield 'direction letter' => ['27.75N', '129.05', true, '27.75', '129.05'];
        yield 'explicit plus' => ['+27.75', '129.05', true, '27.75', '129.05'];
        yield 'negative zero' => ['-0.00', '129.05', true, '0.00', '129.05'];
        yield 'longitude only differs' => ['27.75', '129.050', true, '27.75', '129.05'];
        yield 'integer' => ['27', '129', true, '27.00', '129.00'];
        yield 'negative' => ['-27.75', '-129.05', false, '-27.75', '-129.05'];
    }

    #[DataProvider('canonicalProvider')]
    public function testCanonicalFormAndRedirect(string $latitude, string $longitude, bool $needsRedirect, string $canonicalLatitude, string $canonicalLongitude): void
    {
        $query = $this->parser->parse($latitude, $longitude);

        self::assertTrue($query->isValid());
        self::assertSame($canonicalLatitude, $query->canonicalLatitude);
        self::assertSame($canonicalLongitude, $query->canonicalLongitude);
        self::assertSame($needsRedirect, $query->needsRedirect());
        self::assertNull($query->ignoredField);
    }

    public function testInvalidInputHasNoCanonicalForm(): void
    {
        $query = $this->parser->parse('27.75E', '129.05');

        self::assertNull($query->canonicalLatitude);
        self::assertNull($query->canonicalLongitude);
        self::assertFalse($query->needsRedirect());
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

    /**
     * @return iterable<string, array{string, string, float, float, string, string, string|null}>
     */
    public static function pairInOneFieldProvider(): iterable
    {
        // [緯度欄, 経度欄, 緯度, 経度, 正規形の緯度, 正規形の経度, 使わなかった欄]
        yield 'latitude field, empty longitude' => ['27.75, 129.05', '', 27.75, 129.05, '27.75', '129.05', null];
        yield 'latitude field, longitude ignored' => ['35.10, 139.20', '129.05', 35.10, 139.20, '35.10', '139.20', 'longitude'];
        yield 'latitude field, unreadable longitude ignored' => ['35.10, 139.20', 'abc', 35.10, 139.20, '35.10', '139.20', 'longitude'];
        yield 'longitude field, empty latitude' => ['', '27.75, 129.05', 27.75, 129.05, '27.75', '129.05', null];
        yield 'longitude field, latitude ignored' => ['10', '27.75, 129.05', 27.75, 129.05, '27.75', '129.05', 'latitude'];
        yield 'direction letters decide the axis' => ['129.05E 27.75N', '', 27.75, 129.05, '27.75', '129.05', null];
        yield 'both within latitude range stays in order' => ['35.00, 45.00', '', 35.0, 45.0, '35.00', '45.00', null];
        yield 'rounded' => ['27.755, 129.045', '', 27.76, 129.05, '27.76', '129.05', null];
    }

    #[DataProvider('pairInOneFieldProvider')]
    public function testReadsPairFromOneField(string $latitudeField, string $longitudeField, float $latitude, float $longitude, string $canonicalLatitude, string $canonicalLongitude, ?string $ignoredField): void
    {
        $query = $this->parser->parse($latitudeField, $longitudeField);

        self::assertTrue($query->isValid());
        self::assertSame($latitude, $query->latitude);
        self::assertSame($longitude, $query->longitude);
        self::assertSame($canonicalLatitude, $query->canonicalLatitude);
        self::assertSame($canonicalLongitude, $query->canonicalLongitude);
        self::assertSame($ignoredField, $query->ignoredField);
        self::assertTrue($query->needsRedirect());
    }

    public function testPairInBothFieldsIsAnErrorOnBoth(): void
    {
        $message = '「緯度, 経度」はどちらか一方の欄だけに入力してください';

        $query = $this->parser->parse('27.75, 129.05', '27.75, 129.05');

        self::assertFalse($query->isValid());
        self::assertSame(['latitude' => $message, 'longitude' => $message], $query->errors);
    }

    public function testMistakeInsideThePairIsShownOnlyOnThatField(): void
    {
        $query = $this->parser->parse('27.75, 129.05, 10', '129.05');

        self::assertFalse($query->isValid());
        self::assertSame(['latitude' => '緯度と経度の 2 つだけを入力してください。'.self::EXAMPLES], $query->errors);
    }

    public function testMistakeInsideThePairInTheLongitudeField(): void
    {
        $query = $this->parser->parse('27.75', '27.75, abc');

        self::assertSame(['longitude' => '経度を読み取れませんでした。'.self::EXAMPLES], $query->errors);
    }

    public function testSwappedOrderIsReportedNotCorrected(): void
    {
        $query = $this->parser->parse('129.05, 27.75', '');

        self::assertFalse($query->isValid());
        self::assertNull($query->latitude);
        self::assertSame(
            ['latitude' => '緯度と経度の順序が逆になっている可能性があります。「緯度, 経度」の順で入力してください（例：27.75, 129.05）'],
            $query->errors,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outOfRangePairProvider(): iterable
    {
        yield 'both out of range reports latitude' => ['95.00, 200.00', self::LATITUDE_RANGE];
        yield 'longitude out of range' => ['27.75, 200', self::LONGITUDE_RANGE];
        // 入れ替えると 129 が緯度の範囲外なので、順序が逆とは案内しない
        yield 'swapping would not help' => ['95, 129', self::LATITUDE_RANGE];
        // 方角の文字があるので、順序の誤りとは見なさない
        yield 'with direction letters' => ['N95 E129', self::LATITUDE_RANGE];
    }

    #[DataProvider('outOfRangePairProvider')]
    public function testOutOfRangeInPairNamesTheValueOutOfRange(string $input, string $message): void
    {
        $query = $this->parser->parse($input, '');

        self::assertSame(['latitude' => $message], $query->errors);
    }

    public function testOutOfRangePairInTheLongitudeFieldShowsErrorOnThatField(): void
    {
        $query = $this->parser->parse('', '27.75, 200');

        self::assertSame(['longitude' => self::LONGITUDE_RANGE], $query->errors);
    }

    /**
     * @return iterable<string, array{string, string, string|null, string|null}>
     */
    public static function ignoredParameterProvider(): iterable
    {
        yield 'lon' => ['35.10', '139.20', 'lon', 'longitude'];
        yield 'lat' => ['35.10', '139.20', 'lat', 'latitude'];
        yield 'unknown value' => ['35.10', '139.20', 'foo', null];
        yield 'none' => ['35.10', '139.20', null, null];
        yield 'empty' => ['35.10', '139.20', '', null];
        // 正規形でない入力では、利用者が URL に付けた値を使わない
        yield 'not canonical' => ['35.1', '139.20', 'lon', null];
    }

    #[DataProvider('ignoredParameterProvider')]
    public function testIgnoredParameter(string $latitude, string $longitude, ?string $ignored, ?string $expected): void
    {
        $query = $this->parser->parse($latitude, $longitude, $ignored);

        self::assertSame($expected, $query->ignoredField);
    }

    public function testDegreeMinuteRangeIsCheckedBeforeRounding(): void
    {
        self::assertSame(['latitude' => self::LATITUDE_RANGE], $this->parser->parse("90°00.1'N", '129.05')->errors);
        self::assertTrue($this->parser->parse('27.75', '180°00\'00"W')->isValid());
        self::assertSame(['latitude' => self::LATITUDE_RANGE], $this->parser->parse('90°00\'01"', '129.05')->errors);
    }

    public function testDegreeMinuteErrorMessages(): void
    {
        self::assertSame(
            ['latitude' => '度分・度分秒で入力するときは、度を整数にしてください（例：27°45.0\'）'],
            $this->parser->parse("27.5°30'", '129.05')->errors,
        );
        self::assertSame(
            ['latitude' => '分・秒は 0 以上 60 未満で入力してください'],
            $this->parser->parse("27°75'N", '129.05')->errors,
        );
    }

    public function testDegreeMinuteAxisMismatch(): void
    {
        self::assertSame(
            ['latitude' => '緯度には N（北緯）か S（南緯）を付けてください。E・W は経度に使います'],
            $this->parser->parse("27°45'E", '129.05')->errors,
        );
    }

    public function testDegreeMinutesAreConvertedToCanonicalDecimals(): void
    {
        $query = $this->parser->parse("27°45.0'N", "129°03.0'E");

        self::assertTrue($query->isValid());
        self::assertSame(27.75, $query->latitude);
        self::assertSame(129.05, $query->longitude);
        self::assertSame('27.75', $query->canonicalLatitude);
        self::assertSame('129.05', $query->canonicalLongitude);
        self::assertTrue($query->needsRedirect());
    }

    public function testLinkMessage(): void
    {
        self::assertSame(
            ['latitude' => 'リンクからは地点を読み取れません。地図アプリで緯度・経度をコピーして貼り付けてください'],
            $this->parser->parse('https://maps.app.goo.gl/xxxx', '129.05')->errors,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairErrorMessageProvider(): iterable
    {
        yield 'unitless degree minutes' => ['27 45.0 129 03.0', '度分で入力するときは、度と分の記号を付けてください（例：27°45.0\' 129°03.0\'）'];
        yield 'direction on one value' => ['N27 45.0', '緯度と経度の両方に方角の文字を付けるか、両方とも付けずに入力してください'];
        yield 'same axis' => ['27N 129N', '緯度（N・S）と経度（E・W）を 1 つずつ入力してください'];
        yield 'too many values' => ['27.75, 129.05, 10', '緯度と経度の 2 つだけを入力してください。'.self::EXAMPLES];
        yield 'unreadable second value' => ['27.75, abc', '緯度を読み取れませんでした。'.self::EXAMPLES];
    }

    #[DataProvider('pairErrorMessageProvider')]
    public function testPairErrorMessages(string $input, string $message): void
    {
        $query = $this->parser->parse($input, '');

        self::assertFalse($query->isValid());
        self::assertSame(['latitude' => $message], $query->errors);
    }

    public function testTooLongMessageNamesTheField(): void
    {
        self::assertSame(
            ['longitude' => '経度の入力が長すぎます。100 文字以内で入力してください'],
            $this->parser->parse('27.75', str_repeat('1', 101))->errors,
        );
    }
}
