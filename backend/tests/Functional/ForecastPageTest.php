<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
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

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }
}
