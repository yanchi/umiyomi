<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Input;

use App\Presentation\Web\Input\Axis;
use App\Presentation\Web\Input\CoordinateNotationParser;
use App\Presentation\Web\Input\FieldKind;
use App\Presentation\Web\Input\NotationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinateNotationParserTest extends TestCase
{
    private CoordinateNotationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CoordinateNotationParser();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function normalizationProvider(): iterable
    {
        yield 'surrounding spaces' => [' 27.75 ', 2775];
        yield 'full-width digits and period' => ['２７．７５', 2775];
        yield 'full-width spaces' => ["\u{3000}27.75\u{3000}", 2775];
        yield 'tabs' => ["\t27.75\t", 2775];
        yield 'full-width minus' => ['－27.75', -2775];
        yield 'minus sign U+2212' => ['−27.75', -2775];
        yield 'full-width plus' => ['＋27.75', 2775];
        yield 'parentheses' => ['(27.75)', 2775];
        yield 'full-width parentheses' => ['（27.75）', 2775];
    }

    #[DataProvider('normalizationProvider')]
    public function testNormalizesBeforeReading(string $input, int $hundredths): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame($hundredths, $field->first?->hundredths);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalProvider(): iterable
    {
        yield 'integer' => ['27', 2700];
        yield 'trailing dot' => ['27.', 2700];
        yield 'leading dot' => ['.5', 50];
        yield 'explicit plus' => ['+27.75', 2775];
        yield 'negative' => ['-27.75', -2775];
        yield 'degree mark' => ['27.75°', 2775];
        yield 'degree mark with space' => ['27.75 °', 2775];
    }

    #[DataProvider('decimalProvider')]
    public function testReadsDecimal(string $input, int $hundredths): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame($hundredths, $field->first?->hundredths);
        self::assertNull($field->first->axis);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function roundingProvider(): iterable
    {
        // FR-023：浮動小数点を通さず、十進数として正確に四捨五入する（0 から遠い方向）
        yield 'round up' => ['27.755', 2776];
        yield 'round up negative' => ['-27.755', -2776];
        yield 'round down' => ['27.754', 2775];
        yield 'many nines below the boundary' => ['27.7549999999999999', 2775];
        yield 'trailing zeros' => ['27.7500', 2775];
        yield 'smallest positive' => ['0.005', 1];
        yield 'negative rounds to zero' => ['-0.004', 0];
        yield 'three digit degrees' => ['129.045', 12905];
        yield 'carry into degrees' => ['27.995', 2800];
    }

    #[DataProvider('roundingProvider')]
    public function testRoundsExactly(string $input, int $hundredths): void
    {
        self::assertSame($hundredths, $this->parser->parse($input)->first?->hundredths);
    }

    /**
     * @return iterable<string, array{string, int, Axis}>
     */
    public static function directionProvider(): iterable
    {
        yield 'suffix N' => ['27.75N', 2775, Axis::Latitude];
        yield 'prefix N' => ['N27.75', 2775, Axis::Latitude];
        yield 'prefix N with space' => ['N 27.75', 2775, Axis::Latitude];
        yield 'suffix lowercase n with space' => ['27.75 n', 2775, Axis::Latitude];
        yield 'full-width N' => ['Ｎ27.75', 2775, Axis::Latitude];
        yield 'kanji north' => ['北緯27.75', 2775, Axis::Latitude];
        yield 'suffix S' => ['27.75S', -2775, Axis::Latitude];
        yield 'kanji south' => ['南緯27.75', -2775, Axis::Latitude];
        yield 'suffix E' => ['129.05E', 12905, Axis::Longitude];
        yield 'kanji east' => ['東経129.05', 12905, Axis::Longitude];
        yield 'suffix W' => ['129.05W', -12905, Axis::Longitude];
        yield 'kanji west' => ['西経129.05', -12905, Axis::Longitude];
    }

    #[DataProvider('directionProvider')]
    public function testReadsDirectionLetters(string $input, int $hundredths, Axis $axis): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame($hundredths, $field->first?->hundredths);
        self::assertSame($axis, $field->first->axis);
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function rangeValueProvider(): iterable
    {
        yield 'exactly 90' => ['90', 90, false];
        yield '90.004 has a fraction' => ['90.004', 90, true];
        yield '90.000 has no fraction' => ['90.000', 90, false];
        yield 'many digits are capped' => ['12345.6', 999, true];
        yield 'many leading zeros are not capped' => ['0000027', 27, false];
    }

    #[DataProvider('rangeValueProvider')]
    public function testKeepsValueBeforeRoundingForRangeCheck(string $input, int $wholeDegrees, bool $hasFraction): void
    {
        $angle = $this->parser->parse($input)->first;

        self::assertNotNull($angle);
        self::assertSame($wholeDegrees, $angle->wholeDegrees);
        self::assertSame($hasFraction, $angle->hasFraction);
    }

    /**
     * @return iterable<string, array{string, NotationError}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => ['', NotationError::Empty];
        yield 'only spaces' => ['   ', NotationError::Empty];
        yield 'only parentheses' => ['()', NotationError::Empty];
        yield 'too long ascii' => [str_repeat('1', 101), NotationError::TooLong];
        yield 'too long full-width' => [str_repeat('１', 101), NotationError::TooLong];
        yield 'invalid utf-8' => ["\xff", NotationError::Unreadable];
        yield 'letters' => ['abc', NotationError::Unreadable];
        yield 'two dots' => ['27.75.1', NotationError::Unreadable];
        yield 'exponent' => ['1e5', NotationError::Unreadable];
        yield 'direction only' => ['N', NotationError::Unreadable];
        yield 'conflicting directions' => ['N27.75S', NotationError::ConflictingDirections];
        yield 'minus and suffix direction' => ['-27.75N', NotationError::SignAndDirection];
        yield 'plus and suffix direction' => ['+27.75N', NotationError::SignAndDirection];
    }

    #[DataProvider('invalidProvider')]
    public function testReportsError(string $input, NotationError $error): void
    {
        $field = $this->parser->parse($input);

        self::assertSame($error, $field->error);
        self::assertSame(NotationError::Empty === $error ? FieldKind::Empty : FieldKind::Invalid, $field->kind);
    }

    public function testHundredCodePointsIsNotTooLong(): void
    {
        $field = $this->parser->parse(str_repeat('1', 100));

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame(999, $field->first?->wholeDegrees);
    }

    /**
     * @return iterable<string, array{string, int, Axis|null, int, Axis|null}>
     */
    public static function pairProvider(): iterable
    {
        // [入力, 1 つ目の値, 1 つ目の軸, 2 つ目の値, 2 つ目の軸]。順序は文字列の順のまま
        yield 'comma and space' => ['27.75, 129.05', 2775, null, 12905, null];
        yield 'comma only' => ['27.75,129.05', 2775, null, 12905, null];
        yield 'space only' => ['27.75 129.05', 2775, null, 12905, null];
        yield 'japanese comma' => ['27.75、129.05', 2775, null, 12905, null];
        yield 'full-width comma' => ['27.75，129.05', 2775, null, 12905, null];
        yield 'parentheses' => ['(27.75, 129.05)', 2775, null, 12905, null];
        yield 'full-width' => ['２７．７５，１２９．０５', 2775, null, 12905, null];
        yield 'iphone maps' => ['27.75000° N, 129.05000° E', 2775, Axis::Latitude, 12905, Axis::Longitude];
        yield 'prefix directions' => ['N27.75 E129.05', 2775, Axis::Latitude, 12905, Axis::Longitude];
        yield 'suffix directions' => ['27.75N 129.05E', 2775, Axis::Latitude, 12905, Axis::Longitude];
        yield 'longitude first' => ['129.05E 27.75N', 12905, Axis::Longitude, 2775, Axis::Latitude];
        yield 'prefix directions with spaces' => ['N 27.75 E 129.05', 2775, Axis::Latitude, 12905, Axis::Longitude];
        yield 'negative' => ['-33.86, 151.21', -3386, null, 15121, null];
        yield 'both within latitude range' => ['35.00, 45.00', 3500, null, 4500, null];
    }

    #[DataProvider('pairProvider')]
    public function testReadsPair(string $input, int $first, ?Axis $firstAxis, int $second, ?Axis $secondAxis): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Pair, $field->kind);
        self::assertSame($first, $field->first?->hundredths);
        self::assertSame($firstAxis, $field->first->axis);
        self::assertSame($second, $field->second?->hundredths);
        self::assertSame($secondAxis, $field->second->axis);
    }

    /**
     * @return iterable<string, array{string, NotationError}>
     */
    public static function invalidPairProvider(): iterable
    {
        yield 'three values' => ['27.75, 129.05, 10', NotationError::TooManyValues];
        yield 'four numbers without symbols' => ['27 45.0 129 03.0', NotationError::UnitlessDegreeMinutes];
        yield 'four integers without symbols' => ['27 45 129 3', NotationError::UnitlessDegreeMinutes];
        yield 'direction on the first only (prefix)' => ['N27 45.0', NotationError::PairDirectionMixed];
        yield 'direction on the first only (suffix)' => ['27.75N, 129.05', NotationError::PairDirectionMixed];
        yield 'both latitude' => ['27N 129N', NotationError::PairSameAxis];
        yield 'both longitude' => ['27.75E 129.05W', NotationError::PairSameAxis];
        yield 'second value unreadable' => ['27.75, abc', NotationError::Unreadable];
        yield 'conflicting directions in the pair' => ['N27.75S, E129.05', NotationError::Unreadable];
    }

    #[DataProvider('invalidPairProvider')]
    public function testReportsPairError(string $input, NotationError $error): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Invalid, $field->kind);
        self::assertSame($error, $field->error);
        self::assertTrue($field->looksLikePair);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function singleProvider(): iterable
    {
        yield 'plain' => ['27.75'];
        yield 'suffix direction with space' => ['27.75 N'];
        yield 'prefix direction with space' => ['N 27.75'];
    }

    #[DataProvider('singleProvider')]
    public function testSpaceAroundDirectionDoesNotMakeAPair(string $input): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame(2775, $field->first?->hundredths);
        self::assertNull($field->second);
    }

    public function testSingleValueErrorsDoNotLookLikeAPair(): void
    {
        foreach (['abc', '27.75.1', '1e5', 'N'] as $input) {
            self::assertFalse($this->parser->parse($input)->looksLikePair, $input);
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function degreeMinuteProvider(): iterable
    {
        yield 'degrees minutes' => ["27°45.0'N", 2775];
        yield 'minute mark omitted' => ["27°45'N", 2775];
        yield 'last mark omitted' => ['27°45.0N', 2775];
        yield 'spaces' => ["27° 45.0' N", 2775];
        yield 'prefix direction' => ["N27°45.0'", 2775];
        yield 'kanji units' => ['27度45分', 2775];
        yield 'kanji north' => ['北緯27度45分', 2775];
        yield 'letter d' => ["27d45.0'", 2775];
        yield 'ordinal indicator and smart quote' => ['27º45.0’', 2775];
        yield 'prime' => ['27°45.0′', 2775];
        yield 'full-width' => ['２７°４５．０′', 2775];
        yield 'longitude' => ["129°03.0'E", 12905];
        yield 'longitude kanji' => ['東経129度3分', 12905];
        yield 'degrees seconds' => ['27°45\'00"N', 2775];
        yield 'seconds as two quotes' => ["27°45'00''N", 2775];
        yield 'prime marks' => ['27°45′00″N', 2775];
        yield 'smart quotes' => ['27°45’00”N', 2775];
        yield 'kanji seconds' => ['27度45分0秒', 2775];
        yield 'seconds mark omitted' => ["27°45'00N", 2775];
        yield 'hyphen prefix' => ['N27-45.0', 2775];
        yield 'hyphen suffix' => ['27-45.0N', 2775];
        yield 'hyphen seconds' => ['N27-45-00', 2775];
        yield 'hyphen longitude' => ['E129-03.0', 12905];
    }

    #[DataProvider('degreeMinuteProvider')]
    public function testReadsDegreesMinutesSeconds(string $input, int $hundredths): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Single, $field->kind, $input);
        self::assertSame($hundredths, $field->first?->hundredths, $input);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function degreeMinuteRoundingProvider(): iterable
    {
        // FR-023：分・秒から十進数に直すときも、浮動小数点を通さずに正確に四捨五入する（0 から遠い方向）
        yield 'minutes at the boundary' => ["27°45.3'", 2776];
        yield 'minutes at the boundary, south' => ["S27°45.3'", -2776];
        yield 'seconds at the boundary' => ['27°45\'18"', 2776];
        yield 'minutes below the boundary' => ["27°45.29'", 2775];
        yield 'seconds below the boundary' => ['27°45\'17.9"', 2775];
        yield 'seconds with a hundredth below the boundary' => ['27°45\'17.95"', 2775];
        yield 'smallest minutes' => ["0°0.3'", 1];
        yield 'smallest seconds' => ['0°0\'18"', 1];
    }

    #[DataProvider('degreeMinuteRoundingProvider')]
    public function testRoundsDegreesMinutesExactly(string $input, int $hundredths): void
    {
        self::assertSame($hundredths, $this->parser->parse($input)->first?->hundredths, $input);
    }

    /**
     * @return iterable<string, array{string, int, Axis|null}>
     */
    public static function degreeMinuteSignProvider(): iterable
    {
        yield 'prefix south' => ["S27°45.0'", -2775, Axis::Latitude];
        yield 'suffix south' => ["27°45.0'S", -2775, Axis::Latitude];
        yield 'minus sign' => ["-27°45.0'", -2775, null];
        yield 'prefix west' => ["W129°03.0'", -12905, Axis::Longitude];
    }

    #[DataProvider('degreeMinuteSignProvider')]
    public function testSignAndDirectionOfDegreesMinutes(string $input, int $hundredths, ?Axis $axis): void
    {
        $field = $this->parser->parse($input);

        self::assertSame($hundredths, $field->first?->hundredths, $input);
        self::assertSame($axis, $field->first->axis, $input);
    }

    /**
     * @return iterable<string, array{string, NotationError}>
     */
    public static function degreeMinuteErrorProvider(): iterable
    {
        yield 'minus and direction' => ["-27°45'N", NotationError::SignAndDirection];
        yield 'decimal degrees' => ["27.5°30'", NotationError::DegreeNotInteger];
        yield 'minutes 75' => ["27°75'N", NotationError::MinuteSecondRange];
        yield 'minutes 60' => ["27°60'", NotationError::MinuteSecondRange];
        yield 'seconds 60' => ['27°45\'60"', NotationError::MinuteSecondRange];
        yield 'decimal minutes with seconds' => ['27°45.5\'30"', NotationError::Unreadable];
        yield 'hyphen without direction' => ['27-45.0', NotationError::Unreadable];
    }

    #[DataProvider('degreeMinuteErrorProvider')]
    public function testReportsDegreeMinuteError(string $input, NotationError $error): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Invalid, $field->kind, $input);
        self::assertSame($error, $field->error, $input);
    }

    public function testKeepsValueBeforeRoundingForDegreeMinuteRange(): void
    {
        $exact = $this->parser->parse('90°00\'00"')->first;
        $above = $this->parser->parse("90°00.1'")->first;

        self::assertNotNull($exact);
        self::assertNotNull($above);
        self::assertSame(90, $exact->wholeDegrees);
        self::assertFalse($exact->hasFraction);
        self::assertSame(90, $above->wholeDegrees);
        self::assertTrue($above->hasFraction);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function degreeMinutePairProvider(): iterable
    {
        yield 'prefix directions' => ["N27°45.0' E129°03.0'"];
        yield 'suffix directions' => ["27°45.0'N 129°03.0'E"];
        yield 'with comma' => ["27°45.0'N, 129°03.0'E"];
        yield 'spaces inside values' => ["27° 45.0' N 129° 03.0' E"];
        yield 'seconds without directions' => ['27°45\'00" 129°03\'00"'];
    }

    #[DataProvider('degreeMinutePairProvider')]
    public function testReadsDegreeMinutePair(string $input): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Pair, $field->kind, $input);
        self::assertSame(2775, $field->first?->hundredths, $input);
        self::assertSame(12905, $field->second?->hundredths, $input);
    }

    public function testSpaceInsideADegreeMinuteValueIsNotASeparator(): void
    {
        $field = $this->parser->parse("27° 45.0'");

        self::assertSame(FieldKind::Single, $field->kind);
        self::assertSame(2775, $field->first?->hundredths);
    }

    /**
     * @return iterable<string, array{string, NotationError}>
     */
    public static function urlProvider(): iterable
    {
        yield 'short link' => ['https://maps.app.goo.gl/xxxx', NotationError::Url];
        yield 'link with coordinates' => ['http://example.com/?q=27.75,129.05', NotationError::Url];
        yield 'upper case' => ['HTTPS://maps.example/27.75,129.05', NotationError::Url];
        yield 'kanji numerals' => ['北緯二十七度', NotationError::Unreadable];
    }

    #[DataProvider('urlProvider')]
    public function testRejectsLinksAndUnsupportedText(string $input, NotationError $error): void
    {
        $field = $this->parser->parse($input);

        self::assertSame(FieldKind::Invalid, $field->kind);
        self::assertSame($error, $field->error);
    }

    public function testLinkIsNotMistakenForAPairOfValues(): void
    {
        self::assertFalse($this->parser->parse('https://example.com/27.75,129.05')->looksLikePair);
    }
}
