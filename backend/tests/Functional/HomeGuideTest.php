<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * トップの見出し構造とサービス内容の本文（007 FR-007〜FR-009）。
 *
 * 本文は検索エンジンが読む静的な文章なので、JavaScript・localStorage に依存せず、サーバーの描画結果だけで確かめる。
 */
final class HomeGuideTest extends WebTestCase
{
    // 航海の安全や出航の可否を断定する表現（FR-012）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

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

    public function testHeadingStructure(): void
    {
        $crawler = $this->home();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('UMIYOMI', $crawler->filter('h1.site-title a')->text());
        self::assertSame('風・波・うねりの予報を出航前に確認', $crawler->filter('h1 .site-title__tagline')->text());

        self::assertSame(
            ['お気に入り', 'UMIYOMI でできること', '使い方', 'ご利用にあたって'],
            $crawler->filter('h2')->each(static fn (Crawler $h2): string => trim($h2->text())),
        );
        self::assertSame(
            ['UMIYOMI でできること', '使い方', 'ご利用にあたって'],
            $crawler->filter('section.home-guide h2')->each(static fn (Crawler $h2): string => trim($h2->text())),
        );
        self::assertSame(0, $this->provider()->callCount());
    }

    public function testGuideComesAfterFormAndFavorites(): void
    {
        $this->home();

        $this->assertOrder('class="coordinate-form"', 'class="home-guide"');
        $this->assertOrder('data-favorites="manage-list"', 'class="home-guide"');
    }

    public function testGuideContent(): void
    {
        $guide = $this->home()->filter('section.home-guide');
        self::assertCount(1, $guide);

        $text = $guide->text();
        foreach (['UMIYOMI（ウミヨミ）', '風速', '風向', '波高', '波向', '波周期', 'うねり', '緯度 27.75、経度 129.05', '航海の安全を保証するものではありません', '気象庁', '警報・注意報', '最終更新'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    public function testGuideHasNoLinks(): void
    {
        // 予報画面へのリンク・特定の地点を勧める表現を置かない（FR-007a）
        self::assertCount(0, $this->home()->filter('section.home-guide a[href]'));
    }

    public function testGuideIsNotHidden(): void
    {
        $crawler = $this->home();

        self::assertCount(0, $crawler->filter('section.home-guide[hidden], section.home-guide [hidden]'));
        $hiddenAncestors = $crawler->filter('section.home-guide')->ancestors()->reduce(static fn (Crawler $node): bool => null !== $node->attr('hidden'));
        self::assertCount(0, $hiddenAncestors);
    }

    public function testNoAssertivePhrases(): void
    {
        $crawler = $this->home();

        $texts = [
            $crawler->filter('h1')->text(),
            $crawler->filter('header .lead')->text(),
            $crawler->filter('section.home-guide')->text(),
        ];
        foreach ($texts as $text) {
            foreach (self::ASSERTIVE_PHRASES as $phrase) {
                self::assertStringNotContainsString($phrase, $text);
            }
        }
    }

    private function home(): Crawler
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function assertOrder(string $earlier, string $later): void
    {
        $html = (string) $this->client->getResponse()->getContent();
        $earlierPosition = strpos($html, $earlier);
        $laterPosition = strpos($html, $later);
        self::assertNotFalse($earlierPosition, $earlier);
        self::assertNotFalse($laterPosition, $later);
        self::assertLessThan($laterPosition, $earlierPosition, \sprintf('%s は %s より前にある', $earlier, $later));
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }
}
