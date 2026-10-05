<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Feedback;

use App\Presentation\Web\Feedback\FeedbackFormLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedbackFormLinkTest extends TestCase
{
    private const string URL = 'https://forms.example.test/feedback?usp=pp_url';

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function availabilityProvider(): iterable
    {
        yield 'configured' => [self::URL, 'entry.1000', true];
        yield 'empty url' => ['', 'entry.1000', false];
        yield 'empty field' => [self::URL, '', false];
        yield 'both empty' => ['', '', false];
        yield 'http' => ['http://forms.example.test/feedback', 'entry.1000', false];
        yield 'fragment' => ['https://forms.example.test/feedback#top', 'entry.1000', false];
    }

    #[DataProvider('availabilityProvider')]
    public function testIsAvailable(string $url, string $field, bool $expected): void
    {
        self::assertSame($expected, new FeedbackFormLink($url, $field)->isAvailable());
    }

    public function testUrlWithoutPrefillIsReturnedAsIs(): void
    {
        self::assertSame(self::URL, new FeedbackFormLink(self::URL, 'entry.1000')->urlFor(null));
    }

    public function testUrlWithQueryJoinsWithAmpersand(): void
    {
        $url = new FeedbackFormLink(self::URL, 'entry.1000')->urlFor('緯度 27.75・経度 129.05');

        self::assertSame(self::URL.'&entry.1000='.rawurlencode('緯度 27.75・経度 129.05'), $url);
        self::assertStringContainsString('entry.1000=', $url);
        self::assertStringNotContainsString('entry_1000', $url);
    }

    public function testUrlWithoutQueryJoinsWithQuestionMark(): void
    {
        $url = new FeedbackFormLink('https://tally.so/r/XXX', 'context')->urlFor('緯度 27.75');

        self::assertSame('https://tally.so/r/XXX?context='.rawurlencode('緯度 27.75'), $url);
    }

    public function testSpecialCharactersAreEncoded(): void
    {
        $url = new FeedbackFormLink(self::URL, 'entry.1000')->urlFor('a&b=c#d e');

        self::assertSame(self::URL.'&entry.1000=a%26b%3Dc%23d%20e', $url);
    }

    public function testUrlForThrowsWhenUnavailable(): void
    {
        $this->expectException(\LogicException::class);

        new FeedbackFormLink('', '')->urlFor(null);
    }
}
