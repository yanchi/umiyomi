<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Marine\Availability;
use App\Tests\Support\FakeMarineForecastProvider;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ForecastPageTest extends WebTestCase
{
    private const string DISCLAIMER = 'この予報は航海の安全を保証するものではありません。出航前に気象庁などが発表する警報・注意報もあわせて確認してください。';

    // 航海の安全や出航の可否を断定する表現（FR-010）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Fake Provider・固定時計・キャッシュの状態をリクエスト間で保つ
        $this->client->disableReboot();

        // ファイルシステムのプールはテストをまたいで残るため、毎回空にする
        foreach (['cache.marine_forecast', 'cache.rate_limiter'] as $pool) {
            $service = self::getContainer()->get($pool);
            \assert($service instanceof CacheItemPoolInterface);
            $service->clear();
        }
    }

    public function testHomeShowsCoordinateForm(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form');
        self::assertSame('get', strtolower((string) $form->attr('method')));
        self::assertSame('/forecast', $form->attr('action'));
        self::assertCount(1, $crawler->filter('input[name="lat"]'));
        self::assertCount(1, $crawler->filter('input[name="lon"]'));
        self::assertSelectorTextContains('form button', '予報を表示');
    }

    public function testForecastPageShowsTable(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('body', '北緯 27.75° / 東経 129.05°');
        self::assertSelectorTextContains('body', '最終更新：2026/10/05 20:15（21:15以降に再取得）');
        self::assertSelectorTextContains('body', self::DISCLAIMER);
        self::assertCount(9, $crawler->filter('table tbody tr'));
        self::assertSelectorTextContains('table tbody tr th', '風速 (m/s)');
        self::assertSelectorTextContains('footer', 'Weather data by Open-Meteo.com');
        self::assertSame('https://open-meteo.com/', $crawler->filter('footer a:not(.site-footer__feedback):not(.site-footer__external-transmission)')->attr('href'));
    }

    public function testSameLocationIsFetchedOnlyOnce(): void
    {
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->provider()->callCount());
    }

    public function testOutOfRangeLatitudeIs422(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=95&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '緯度は -90〜90 の範囲で入力してください');
        self::assertSame('true', $crawler->filter('#lat')->attr('aria-invalid'));
        self::assertSame('lat-error', $crawler->filter('#lat')->attr('aria-describedby'));
        self::assertCount(0, $crawler->filter('table'));
        self::assertSame(0, $this->provider()->callCount());
    }

    public function testMalformedLongitudeIs422AndKeepsInput(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=abc');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lon-error', '経度を読み取れませんでした。入力例：');
        self::assertSame('abc', $crawler->filter('#lon')->attr('value'));
        self::assertSame('27.75', $crawler->filter('#lat')->attr('value'));
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testEmptyLongitudeIs422(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lon-error', '経度を入力してください');
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testFetchFailureWithoutPreviousForecastIs503(): void
    {
        $this->provider()->willFail();

        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', '予報を取得できませんでした。時間をおいて再度お試しください');
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testFetchFailureShowsPreviousForecastWithWarning(): void
    {
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $this->clock()->advance('+3 hours');
        $this->provider()->willFail();

        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('[role="alert"]', '2026/10/05 20:15 時点の予報です');
        self::assertSelectorTextContains('body', '最終更新：2026/10/05 20:15');
        self::assertSelectorTextNotContains('body', '以降に再取得');
        self::assertCount(9, $crawler->filter('table tbody tr'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($html, '<table'), strpos($html, 'role="alert"'));
    }

    public function testRateLimitAfter30NewLocations(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', \sprintf('/forecast?lat=%.2f&lon=129.05', 10 + $i));
            self::assertResponseStatusCodeSame(200);
        }

        $crawler = $this->client->request('GET', '/forecast?lat=45.00&lon=129.05');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[role="alert"]', 'しばらく待ってから再度お試しください');
        self::assertCount(0, $crawler->filter('table'));
        self::assertSame(30, $this->provider()->callCount());

        // 再利用できる予報がある地点は回数に数えないので、上限に達したあとでも表示できる
        $this->client->request('GET', '/forecast?lat=10.00&lon=129.05');
        self::assertResponseStatusCodeSame(200);
    }

    public function testLocationWithoutSeaForecast(): void
    {
        $this->provider()->willReturnSeaAvailability(Availability::NotProvidedAtLocation);

        $crawler = $this->client->request('GET', '/forecast?lat=36.65&lon=138.18');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('body', 'この地点では波・うねりの予報が得られません');
        self::assertSame(['風速 (m/s)', '突風 (m/s)', '風向'], $crawler->filter('table tbody th')->each(static fn ($node): string => $node->text()));
    }

    public function testReopeningUrlShowsSameForecastAndFilledForm(): void
    {
        $first = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $second = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first->filter('.location')->text(), $second->filter('.location')->text());
        self::assertSame($first->filter('.last-updated')->text(), $second->filter('.last-updated')->text());
        self::assertSame('27.75', $second->filter('#lat')->attr('value'));
        self::assertSame('129.05', $second->filter('#lon')->attr('value'));
    }

    public function testSwitchingLocationFromForecastPage(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        $form = $crawler->selectButton('予報を表示')->form(['lat' => '35.00', 'lon' => '139.80']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/forecast?lat=35.00&lon=139.80', $this->client->getRequest()->getRequestUri());
        self::assertSelectorTextContains('.location', '北緯 35.00° / 東経 139.80°');
    }

    public function testEquivalentCoordinatesShareCachedForecast(): void
    {
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $this->client->request('GET', '/forecast?lat=27.7500&lon=129.05');

        // 正規形でない入力はリダイレクトされ、リダイレクト元では予報を取得しない
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->provider()->callCount());

        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->provider()->callCount());
    }

    public function testFullWidthInputRedirectsToCanonicalUrl(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('２７．７５').'&lon=129.05');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=27.75&lon=129.05');
        self::assertSame(0, $this->provider()->callCount());

        $crawler = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('27.75', $crawler->filter('#lat')->attr('value'));
        self::assertSelectorTextContains('.location', '北緯 27.75°');
    }

    public function testInputIsRoundedExactlyInRedirect(): void
    {
        $this->client->request('GET', '/forecast?lat=27.755&lon=129.05');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=27.76&lon=129.05');
    }

    public function testRedirectIsNotCountedByRateLimiter(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', '/forecast?lat=27.7500&lon=129.05');
            self::assertResponseStatusCodeSame(303);
        }

        $this->client->request('GET', '/forecast?lat=45.00&lon=129.05');

        self::assertResponseStatusCodeSame(200);
    }

    public function testCanonicalUrlDoesNotRedirect(): void
    {
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseNotHasHeader('Location');
    }

    public function testAxisMismatchIs422AndKeepsInput(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75E&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', 'E・W は経度に使います');
        self::assertSame('27.75E', $crawler->filter('#lat')->attr('value'));
        self::assertSame(0, $this->provider()->callCount());
    }

    public function testPairInLatitudeFieldRedirectsAndFillsBothFields(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('27.75, 129.05').'&lon=');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=27.75&lon=129.05');

        $crawler = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('27.75', $crawler->filter('#lat')->attr('value'));
        self::assertSame('129.05', $crawler->filter('#lon')->attr('value'));
    }

    public function testIphoneMapsFormatRedirectsToDecimal(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('27.75000° N, 129.05000° E').'&lon=');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=27.75&lon=129.05');
    }

    public function testIgnoredFieldIsReportedAfterRedirect(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('35.10, 139.20').'&lon=129.05');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=35.10&lon=139.20&ignored=lon');

        $crawler = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('[role="status"].input-notice', '経度欄に入っていた値は使っていません');
        self::assertSame('35.10', $crawler->filter('#lat')->attr('value'));

        // ignored のない URL（入力欄からの送信・お気に入り）では通知を出さない
        $crawler = $this->client->request('GET', '/forecast?lat=35.10&lon=139.20');

        self::assertCount(0, $crawler->filter('.input-notice'));
    }

    public function testPairInLongitudeFieldRedirects(): void
    {
        $this->client->request('GET', '/forecast?lat=&lon='.rawurlencode('27.75, 129.05'));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', '/forecast?lat=27.75&lon=129.05');
    }

    public function testPairInBothFieldsIs422(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat='.rawurlencode('27.75, 129.05').'&lon='.rawurlencode('27.75, 129.05'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '「緯度, 経度」はどちらか一方の欄だけに入力してください');
        self::assertSelectorTextContains('#lon-error', '「緯度, 経度」はどちらか一方の欄だけに入力してください');
        self::assertSame(0, $this->provider()->callCount());
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testSwappedOrderIs422AndKeepsInput(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat='.rawurlencode('129.05, 27.75').'&lon=');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '緯度と経度の順序が逆になっている可能性があります');
        self::assertSame('129.05, 27.75', $crawler->filter('#lat')->attr('value'));
    }

    public function testPairThatFitsLatitudeRangeIsNotSwapped(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('35.00, 45.00').'&lon=');

        self::assertResponseStatusCodeSame(303);

        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.location', '北緯 35.00° / 東経 45.00°');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function degreeMinuteRedirectProvider(): iterable
    {
        yield 'degrees minutes' => ["27°45.0'N", "129°03.0'E", '/forecast?lat=27.75&lon=129.05'];
        yield 'degrees minutes seconds' => ['27°45\'00"N', '129°03\'00"E', '/forecast?lat=27.75&lon=129.05'];
        yield 'one line' => ["N27°45.0' E129°03.0'", '', '/forecast?lat=27.75&lon=129.05'];
        yield 'rounding and south west' => ["S27°45.3'", "W129°03.0'", '/forecast?lat=-27.76&lon=-129.05'];
    }

    #[DataProvider('degreeMinuteRedirectProvider')]
    public function testDegreeMinuteInputRedirectsToDecimalUrl(string $latitude, string $longitude, string $location): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode($latitude).'&lon='.rawurlencode($longitude));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', $location);
    }

    public function testDegreeMinuteInputIsShownAsDecimals(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode("S27°45.3'").'&lon='.rawurlencode("W129°03.0'"));
        $crawler = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.location', '南緯 27.76° / 西経 129.05°');
        self::assertSame('-27.76', $crawler->filter('#lat')->attr('value'));
        self::assertSame('-129.05', $crawler->filter('#lon')->attr('value'));
    }

    public function testMinutesOutOfRangeIs422(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode("27°75'N").'&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '分・秒は 0 以上 60 未満で入力してください');
    }

    public function testUnreadableInputShowsExamplesNearTheField(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat='.rawurlencode('北緯二十七度').'&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '入力例：27.75 / 27.75, 129.05 / 27°45.0\'N / 27°45\'00"N');
        self::assertSame('lat-error', $crawler->filter('#lat')->attr('aria-describedby'));
        self::assertSame('北緯二十七度', $crawler->filter('#lat')->attr('value'));
        self::assertSame(0, $this->provider()->callCount());
    }

    public function testLinkIsRejectedWithGuidance(): void
    {
        $this->client->request('GET', '/forecast?lat='.rawurlencode('https://maps.app.goo.gl/xxxx').'&lon=');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', 'リンクからは地点を読み取れません');
    }

    public function testTooLongInputIs422(): void
    {
        $this->client->request('GET', '/forecast?lat='.str_repeat('1', 101).'&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '入力が長すぎます');
    }

    public function testConflictingDirectionsIs422(): void
    {
        $this->client->request('GET', '/forecast?lat=N27.75S&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lat-error', '方角の文字が複数あります');
    }

    public function testUnknownQueryParametersAreIgnored(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05&utm_source=bookmark');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.location', '北緯 27.75° / 東経 129.05°');
        self::assertCount(9, $crawler->filter('table tbody tr'));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function screenProvider(): iterable
    {
        // [画面, 予報ページか, 一覧があるか]
        yield 'home' => ['home', false, false];
        yield 'fresh' => ['fresh', true, true];
        yield 'stale' => ['stale', true, true];
        yield 'unavailable' => ['unavailable', true, false];
        yield 'rate limited' => ['rate_limited', true, false];
        yield 'invalid input' => ['invalid_input', true, false];
    }

    #[DataProvider('screenProvider')]
    public function testScreensGiveFactsWithoutAssertingSafety(string $screen, bool $isForecastPage, bool $hasTable): void
    {
        $crawler = $this->open($screen);
        $text = $crawler->filter('body')->text();

        foreach (self::ASSERTIVE_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $text);
        }
        if ($isForecastPage) {
            self::assertStringContainsString(self::DISCLAIMER, $text);
        }
        self::assertCount($hasTable ? 1 : 0, $crawler->filter('table'));
        if ($hasTable) {
            self::assertMatchesRegularExpression('/最終更新：\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}/', $text);
        }
    }

    private function open(string $screen): Crawler
    {
        switch ($screen) {
            case 'home':
                return $this->client->request('GET', '/');
            case 'stale':
                $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
                $this->clock()->advance('+3 hours');
                $this->provider()->willFail();
                break;
            case 'unavailable':
                $this->provider()->willFail();
                break;
            case 'rate_limited':
                for ($i = 0; $i < 30; ++$i) {
                    $this->client->request('GET', \sprintf('/forecast?lat=%.2f&lon=120.00', 10 + $i));
                }
                break;
            case 'invalid_input':
                return $this->client->request('GET', '/forecast?lat=N27&lon=');
        }

        return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }

    private function clock(): FixedClock
    {
        $clock = self::getContainer()->get(FixedClock::class);
        \assert($clock instanceof FixedClock);

        return $clock;
    }
}
