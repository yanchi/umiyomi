<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Input;

use App\Presentation\Web\Input\FeedbackContextParser;
use App\Presentation\Web\Input\FeedbackContextType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedbackContextParserTest extends TestCase
{
    private FeedbackContextParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FeedbackContextParser();
    }

    public function testForecastWithLastUpdated(): void
    {
        $context = $this->parser->parse('27.75', '129.05', '2026/10/05 09:00', '', '');

        self::assertSame(FeedbackContextType::Forecast, $context->type);
        self::assertSame('27.75', $context->latitude);
        self::assertSame('129.05', $context->longitude);
        self::assertSame('2026/10/05 09:00', $context->lastUpdated);
    }

    public function testForecastWithoutLastUpdated(): void
    {
        $context = $this->parser->parse('27.75', '129.05', '', '', '');

        self::assertSame(FeedbackContextType::Forecast, $context->type);
        self::assertNull($context->lastUpdated);
    }

    public function testForecastWinsOverInput(): void
    {
        $context = $this->parser->parse('27.75', '129.05', '', 'abc', 'def');

        self::assertSame(FeedbackContextType::Forecast, $context->type);
        self::assertNull($context->inputLatitude);
        self::assertNull($context->inputLongitude);
    }

    public function testRejectedInput(): void
    {
        $context = $this->parser->parse('', '', '', '北緯二十七度', '129.05');

        self::assertSame(FeedbackContextType::RejectedInput, $context->type);
        self::assertSame('北緯二十七度', $context->inputLatitude);
        self::assertSame('129.05', $context->inputLongitude);
    }

    public function testNone(): void
    {
        self::assertSame(FeedbackContextType::None, $this->parser->parse('', '', '', '', '')->type);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function coordinateProvider(): iterable
    {
        foreach (['90.00', '-90.00', '0.00', '27.75'] as $valid) {
            yield 'latitude '.$valid => [$valid, true];
        }
        foreach (['90.01', '-180.01', '27.7', '27.750', '27', '+27.75', ' 27.75', '２７．７５', '1000.00', '27.75N'] as $invalid) {
            yield 'latitude '.$invalid => [$invalid, false];
        }
    }

    #[DataProvider('coordinateProvider')]
    public function testLatitudeShapeAndRange(string $latitude, bool $valid): void
    {
        $context = $this->parser->parse($latitude, '129.05', '', '', '');

        self::assertSame($valid ? FeedbackContextType::Forecast : FeedbackContextType::None, $context->type);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function longitudeProvider(): iterable
    {
        yield '180.00' => ['180.00', true];
        yield '-180.00' => ['-180.00', true];
        yield '180.01' => ['180.01', false];
        yield '-180.01' => ['-180.01', false];
    }

    #[DataProvider('longitudeProvider')]
    public function testLongitudeRange(string $longitude, bool $valid): void
    {
        $context = $this->parser->parse('27.75', $longitude, '', '', '');

        self::assertSame($valid ? FeedbackContextType::Forecast : FeedbackContextType::None, $context->type);
    }

    public function testOnlyOneValidCoordinateIsNotUsed(): void
    {
        self::assertSame(FeedbackContextType::None, $this->parser->parse('27.75', 'abc', '', '', '')->type);
        self::assertSame(FeedbackContextType::RejectedInput, $this->parser->parse('27.75', 'abc', '', 'x', '')->type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUpdatedProvider(): iterable
    {
        foreach (['2026/02/30 09:00', '2026/10/05 24:00', '2026-10-05 09:00', '2026/10/5 09:00', '任意の文章'] as $updated) {
            yield $updated => [$updated];
        }
    }

    #[DataProvider('invalidUpdatedProvider')]
    public function testInvalidUpdatedIsDropped(string $updated): void
    {
        $context = $this->parser->parse('27.75', '129.05', $updated, '', '');

        self::assertSame(FeedbackContextType::Forecast, $context->type);
        self::assertNull($context->lastUpdated);
    }

    public function testUpdatedWithoutLocationIsNone(): void
    {
        self::assertSame(FeedbackContextType::None, $this->parser->parse('', '', '2026/10/05 09:00', '', '')->type);
    }

    public function testInputIsTruncatedTo100Characters(): void
    {
        $context = $this->parser->parse('', '', '', str_repeat('あ', 101), str_repeat('い', 100));

        self::assertSame(100, mb_strlen((string) $context->inputLatitude));
        self::assertSame(str_repeat('い', 100), $context->inputLongitude);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controlCharacterProvider(): iterable
    {
        yield 'newline' => ["27\n75", '27 75'];
        yield 'tab' => ["\t", ' '];
        yield 'del' => ["\x7f", ' '];
    }

    #[DataProvider('controlCharacterProvider')]
    public function testControlCharactersBecomeSpaces(string $input, string $expected): void
    {
        $context = $this->parser->parse('', '', '', $input, 'x');

        self::assertSame($expected, $context->inputLatitude);
    }

    public function testInvalidUtf8BecomesEmpty(): void
    {
        $context = $this->parser->parse('', '', '', "\xff", '129.05');

        self::assertSame(FeedbackContextType::RejectedInput, $context->type);
        self::assertSame('', $context->inputLatitude);
        self::assertSame('129.05', $context->inputLongitude);
    }

    public function testOneEmptyInputFieldStillRejectedInput(): void
    {
        $context = $this->parser->parse('', '', '', '', 'abc');

        self::assertSame(FeedbackContextType::RejectedInput, $context->type);
        self::assertSame('', $context->inputLatitude);
    }

    public function testBothInputsEmptyIsNone(): void
    {
        self::assertSame(FeedbackContextType::None, $this->parser->parse('', '', '', '', '')->type);
        self::assertSame(FeedbackContextType::None, $this->parser->parse('', '', '', "\xff", "\xfe")->type);
    }
}
