<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Marine\Availability;
use App\Tests\Support\FakeMarineForecastProvider;
use App\Tests\Support\FixedClock;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ForecastPageTest extends WebTestCase
{
    private const string DISCLAIMER = 'この予報は航海の安全を保証するものではありません。出航前に気象庁などが発表する警報・注意報もあわせて確認してください。';

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
        self::assertSame('https://open-meteo.com/', $crawler->filter('footer a')->attr('href'));
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
        self::assertSelectorTextContains('#lon-error', '経度を数値（-180〜180）で入力してください');
        self::assertSame('abc', $crawler->filter('#lon')->attr('value'));
        self::assertSame('27.75', $crawler->filter('#lat')->attr('value'));
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testEmptyLongitudeIs422(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#lon-error', '経度を数値（-180〜180）で入力してください');
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
