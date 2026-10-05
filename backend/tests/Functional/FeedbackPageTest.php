<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeedbackPageTest extends WebTestCase
{
    private const string FORM_URL = 'https://forms.example.test/feedback?usp=pp_url';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        foreach (['cache.marine_forecast', 'cache.rate_limiter'] as $pool) {
            $service = self::getContainer()->get($pool);
            \assert($service instanceof CacheItemPoolInterface);
            $service->clear();
        }
    }

    public function testHomeFooterHasFeedbackLink(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('footer a.site-footer__feedback');
        self::assertCount(1, $link);
        self::assertSame('/feedback', $link->attr('href'));
        self::assertSame('フィードバック', trim($link->text()));
        self::assertNull($link->attr('target'));
        self::assertSelectorTextContains('footer', 'Weather data by Open-Meteo.com');
    }

    public function testForecastFooterCarriesLocation(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('/feedback?lat=27.75&lon=129.05', (string) $crawler->filter('a.site-footer__feedback')->attr('href'));
    }

    public function testUnavailableForecastFooterCarriesLocation(): void
    {
        $this->provider()->willFail();

        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('/feedback?lat=27.75&lon=129.05', (string) $crawler->filter('a.site-footer__feedback')->attr('href'));
    }

    public function testRateLimitedForecastFooterCarriesLocation(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', \sprintf('/forecast?lat=%.2f&lon=129.05', 10 + $i));
        }

        $crawler = $this->client->request('GET', '/forecast?lat=45.00&lon=129.05');

        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('/feedback?lat=45.00&lon=129.05', (string) $crawler->filter('a.site-footer__feedback')->attr('href'));
    }

    public function testInvalidInputFooterHasLink(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=abc&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('footer a.site-footer__feedback'));
    }

    public function testFeedbackPage(): void
    {
        $crawler = $this->client->request('GET', '/feedback');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'フィードバック');
        $open = $crawler->filter('a.feedback-open');
        self::assertCount(1, $open);
        self::assertSame(self::FORM_URL, $open->attr('href'));
        self::assertSame('_blank', $open->attr('target'));
        self::assertStringContainsString('noopener', (string) $open->attr('rel'));
        self::assertStringContainsString('noreferrer', (string) $open->attr('rel'));
        self::assertCount(1, $crawler->filter('meta[name="referrer"][content="no-referrer"]'));
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
        self::assertCount(0, $crawler->filter('a.site-footer__feedback'));
    }

    // フォームを開く操作を計測する目印（006）。リンク先の挙動は変えない
    public function testFormLinkHasAnalyticsClickMarker(): void
    {
        $crawler = $this->client->request('GET', '/feedback');

        self::assertSame('feedback_form_open', $crawler->filter('a.feedback-open')->attr('data-analytics-click'));
    }

    public function testBackLinkWithoutContextGoesHome(): void
    {
        $crawler = $this->client->request('GET', '/feedback');

        $back = $crawler->filter('a.feedback-back');
        self::assertSame('/', $back->attr('href'));
        self::assertStringContainsString('トップ画面に戻る', $back->text());
    }

    public function testBackLinkWithLocationGoesToForecast(): void
    {
        $crawler = $this->client->request('GET', '/feedback?lat=27.75&lon=129.05');

        $back = $crawler->filter('a.feedback-back');
        self::assertSame('/forecast?lat=27.75&lon=129.05', $back->attr('href'));
        self::assertStringContainsString('予報画面に戻る', $back->text());
    }

    public function testRoundTripFromForecastReturnsToSameLocation(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $crawler = $this->client->click($crawler->filter('a.site-footer__feedback')->link());
        self::assertResponseStatusCodeSame(200);

        $this->client->click($crawler->filter('a.feedback-back')->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('body', '北緯 27.75° / 東経 129.05°');
    }

    public function testFeedbackPageDoesNotFetchForecast(): void
    {
        $this->client->request('GET', '/feedback');
        $this->client->request('GET', '/feedback?lat=27.75&lon=129.05');

        self::assertSame(0, $this->provider()->callCount());
    }

    public function testPostIsNotAllowed(): void
    {
        $this->client->request('POST', '/feedback');

        self::assertResponseStatusCodeSame(405);
    }

    public function testNotesComeBeforeFormLink(): void
    {
        $crawler = $this->client->request('GET', '/feedback');

        $notes = $crawler->filter('.feedback-notes');
        self::assertCount(1, $notes);
        self::assertStringContainsString('送る前にご確認ください', $notes->text());
        $texts = [
            '返信や対応をお約束するものではありません。',
            '海上での事件・事故の緊急通報は、海上保安庁（118 番）へ連絡してください。このフォームでは受け付けていません。',
            '出航の判断には、気象庁などが発表する警報・注意報もあわせて確認してください。',
        ];
        $html = (string) $this->client->getResponse()->getContent();
        $openPosition = strpos($html, 'feedback-open');
        self::assertNotFalse($openPosition);
        foreach ($texts as $text) {
            self::assertStringContainsString($text, $notes->text());
            $position = strpos($html, $text);
            self::assertNotFalse($position);
            self::assertLessThan($openPosition, $position);
        }
        self::assertGreaterThan($openPosition, strpos($html, '返信用の連絡先の記入は任意です。'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assertivePagesProvider(): iterable
    {
        yield 'none' => ['/feedback'];
        yield 'forecast' => ['/feedback?lat=27.75&lon=129.05&updated=2026%2F10%2F05%2009%3A00'];
        yield 'rejected input' => ['/feedback?input_lat=abc'];
        yield 'home' => ['/'];
    }

    #[DataProvider('assertivePagesProvider')]
    public function testNoAssertivePhrases(string $path): void
    {
        $this->client->request('GET', $path);

        $html = (string) $this->client->getResponse()->getContent();
        foreach (['安全です', '出航できます', '問題ありません'] as $phrase) {
            self::assertStringNotContainsString($phrase, $html);
        }
    }

    public function testForecastPrefillFromForecastScreen(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $lastUpdated = $crawler->filter('.last-updated')->text();
        self::assertSame(1, preg_match('#(\d{4}/\d{2}/\d{2} \d{2}:\d{2})#', $lastUpdated, $matches));

        $crawler = $this->client->click($crawler->filter('a.site-footer__feedback')->link());

        $summary = \sprintf('緯度 27.75・経度 129.05、最終更新 %s', $matches[1]);
        self::assertSelectorTextContains('.feedback-prefill', $summary.' がフォームに入ります。');
        self::assertSelectorTextContains('body', '送る前にフォームで確認でき、送りたくない場合は消せます。');
        // 外部へ渡す値は事前入力の文章だけ（FR-010）
        self::assertSame(self::FORM_URL.'&entry.1000='.rawurlencode($summary), $crawler->filter('a.feedback-open')->attr('href'));
    }

    public function testForecastPrefillFromUnavailableForecast(): void
    {
        $this->provider()->willFail();
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        $this->client->click($crawler->filter('a.site-footer__feedback')->link());

        self::assertSelectorTextContains('.feedback-prefill', '緯度 27.75・経度 129.05 がフォームに入ります。');
        self::assertSelectorTextNotContains('.feedback-prefill', '最終更新');
    }

    public function testRejectedInputPrefillFromInvalidInputScreen(): void
    {
        $crawler = $this->client->request('GET', '/forecast', ['lat' => '北緯二十七度', 'lon' => '129.05']);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->click($crawler->filter('a.site-footer__feedback')->link());

        $quotes = $crawler->filter('blockquote.feedback-quote');
        self::assertCount(2, $quotes);
        self::assertSame('北緯二十七度', trim($quotes->eq(0)->text()));
        self::assertSame('129.05', trim($quotes->eq(1)->text()));
        self::assertStringContainsString('entry.1000=', (string) $crawler->filter('a.feedback-open')->attr('href'));

        $this->client->click($crawler->filter('a.feedback-back')->link());
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $crawler = $this->client->request('GET', '/feedback?lat=91.00&lon=129.05&updated=任意の文章');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('.feedback-prefill'));
        self::assertCount(0, $crawler->filter('blockquote'));
        self::assertSame(self::FORM_URL, $crawler->filter('a.feedback-open')->attr('href'));
        self::assertCount(1, $crawler->filter('.feedback-notes'));
        self::assertStringNotContainsString('任意の文章', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidUpdatedIsIgnoredButLocationIsUsed(): void
    {
        $crawler = $this->client->request('GET', '/feedback?lat=27.75&lon=129.05&updated=任意の文章');

        self::assertSelectorTextContains('.feedback-prefill', '緯度 27.75・経度 129.05 がフォームに入ります。');
        self::assertStringNotContainsString('最終更新', $crawler->filter('.feedback-prefill')->text());
        self::assertStringNotContainsString('任意の文章', (string) $this->client->getResponse()->getContent());
    }

    public function testInputIsEscaped(): void
    {
        $crawler = $this->client->request('GET', '/feedback', ['input_lat' => '<script>alert(1)</script>']);

        self::assertCount(0, $crawler->filter('blockquote script'));
        self::assertSame('<script>alert(1)</script>', trim($crawler->filter('blockquote.feedback-quote')->eq(0)->text()));
    }

    public function testLongInputIsTruncated(): void
    {
        $crawler = $this->client->request('GET', '/feedback', ['input_lat' => str_repeat('あ', 101)]);

        self::assertSame(str_repeat('あ', 100), trim($crawler->filter('blockquote.feedback-quote')->eq(0)->text()));
    }

    public function testNoPrefillFromHome(): void
    {
        $crawler = $this->client->request('GET', '/');
        $crawler = $this->client->click($crawler->filter('a.site-footer__feedback')->link());

        self::assertCount(0, $crawler->filter('.feedback-prefill'));
        self::assertCount(0, $crawler->filter('blockquote'));
        self::assertSame(self::FORM_URL, $crawler->filter('a.feedback-open')->attr('href'));
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }
}
