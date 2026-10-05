<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/**
 * 検索エンジン向けの案内（robots.txt・sitemap・canonical）の検査（007）。
 *
 * 登録対象はトップだけなので、それ以外の画面（エラー画面を含む）に canonical が出ないことも確かめる。
 * 404・500 は本番と同じ error.html.twig で描画させるため、HeadMetaTest と同じく debug を切ったカーネルで動かす。
 */
final class SeoTest extends WebTestCase
{
    private const string SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    // 航海の安全や出航の可否を断定する表現（FR-012）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    private KernelBrowser $client;

    // debug を切ったカーネルは Twig のキャッシュを作り直さないので、変更後のテンプレートを検査するよう消す
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/test');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
        $this->client->disableReboot();

        foreach (['cache.marine_forecast', 'cache.rate_limiter'] as $pool) {
            $service = self::getContainer()->get($pool);
            \assert($service instanceof CacheItemPoolInterface);
            $service->clear();
        }
    }

    public function testRobotsTxt(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $lines = array_map(trim(...), explode("\n", (string) $response->getContent()));
        self::assertContains('User-agent: *', $lines);
        self::assertContains('Disallow:', $lines);
        self::assertContains('Sitemap: http://localhost/sitemap.xml', $lines);
        // 予報画面を禁止すると、クローラーが noindex を読めずアドレスだけが載る（research R2）
        foreach ($lines as $line) {
            self::assertDoesNotMatchRegularExpression('#^Disallow:\s*\S#', $line);
        }
        self::assertSame(0, $this->provider()->callCount());
    }

    public function testSitemap(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $xml = simplexml_load_string((string) $response->getContent());
        self::assertNotFalse($xml);
        self::assertSame('urlset', $xml->getName());
        $urls = $xml->children(self::SITEMAP_NAMESPACE)->url;
        self::assertCount(1, $urls);
        $url = $urls[0];
        self::assertNotNull($url);
        $children = $url->children(self::SITEMAP_NAMESPACE);
        self::assertSame('http://localhost/', (string) $children->loc);
        // 正確な更新日時を持たないので、推測の値を出さない（research R3）
        foreach (['lastmod', 'changefreq', 'priority'] as $name) {
            self::assertCount(0, $children->{$name}, $name);
        }
        self::assertSame(0, $this->provider()->callCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homeUrls(): iterable
    {
        yield 'plain' => ['/'];
        yield 'with query' => ['/?utm_source=x&lat=1'];
    }

    #[DataProvider('homeUrls')]
    public function testHomeCanonicalHasNoQuery(string $url): void
    {
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $canonical = $crawler->filter('head link[rel="canonical"]');
        self::assertCount(1, $canonical);
        self::assertSame('http://localhost/', $canonical->attr('href'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function nonIndexedScreens(): iterable
    {
        yield 'forecast 200' => ['forecast', 200];
        yield 'forecast 422' => ['invalid_input', 422];
        yield 'forecast 429' => ['rate_limited', 429];
        yield 'forecast 503' => ['unavailable', 503];
        yield 'feedback' => ['feedback', 200];
        yield 'external transmission' => ['external_transmission', 200];
        yield 'not found' => ['not_found', 404];
        yield 'server error' => ['server_error', 500];
    }

    // noindex の画面に canonical を出すと、登録しないのに正規のアドレスを示す矛盾したシグナルになる（research R4）
    #[DataProvider('nonIndexedScreens')]
    public function testNoCanonicalOnNonIndexedScreens(string $screen, int $status): void
    {
        $crawler = $this->open($screen);

        self::assertResponseStatusCodeSame($status);
        self::assertCount(0, $crawler->filter('link[rel="canonical"]'));
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homeUrlsForStructuredData(): iterable
    {
        yield 'plain' => ['/'];
        yield 'with query' => ['/?utm_source=x'];
    }

    #[DataProvider('homeUrlsForStructuredData')]
    public function testHomeStructuredData(string $url): void
    {
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $script = $crawler->filter('script[type="application/ld+json"]');
        self::assertCount(1, $script);

        $data = json_decode($script->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        // 画面にない情報（料金・評価など）を入れない（FR-011）
        self::assertSame(
            ['@context', '@type', 'name', 'alternateName', 'url', 'description', 'inLanguage'],
            array_keys($data),
        );
        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('WebSite', $data['@type']);
        self::assertSame('UMIYOMI', $data['name']);
        self::assertSame('ウミヨミ', $data['alternateName']);
        self::assertSame('http://localhost/', $data['url']);
        self::assertSame('ja', $data['inLanguage']);
        self::assertSame($crawler->filter('head meta[name="description"]')->attr('content'), $data['description']);
        self::assertIsString($data['description']);
        foreach (self::ASSERTIVE_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $data['description']);
        }
    }

    // |raw で出すので、値に < や & が入っても </script> から抜け出せないことを生の HTML で確かめる
    public function testStructuredDataIsEscapedForHtml(): void
    {
        $this->client->request('GET', '/');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches));
        self::assertStringNotContainsString('</', $matches[1]);
        self::assertStringNotContainsString('<', $matches[1]);
        self::assertStringNotContainsString('&', $matches[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function originUrls(): iterable
    {
        yield 'robots.txt' => ['/robots.txt'];
        yield 'sitemap' => ['/sitemap.xml'];
        yield 'home' => ['/'];
    }

    // Host ヘッダーは利用者が自由に付けられるので、アドレスは設定済みの DEFAULT_URI から作る（FR-006）
    #[DataProvider('originUrls')]
    public function testAddressesIgnoreHostHeader(string $url): void
    {
        $this->client->request('GET', $url, server: ['HTTP_HOST' => 'evil.example.com']);

        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('evil.example.com', $content);
        self::assertStringContainsString('http://localhost/', $content);
    }

    private function open(string $screen): Crawler
    {
        $this->client->catchExceptions(true);

        switch ($screen) {
            case 'invalid_input':
                return $this->client->request('GET', '/forecast?lat=N27&lon=');
            case 'rate_limited':
                for ($i = 0; $i < 30; ++$i) {
                    $this->client->request('GET', \sprintf('/forecast?lat=%.2f&lon=129.05', 10 + $i));
                }

                return $this->client->request('GET', '/forecast?lat=45.00&lon=129.05');
            case 'unavailable':
                $this->provider()->willFail();

                return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
            case 'feedback':
                return $this->client->request('GET', '/feedback');
            case 'external_transmission':
                return $this->client->request('GET', '/external-transmission');
            case 'not_found':
                return $this->client->request('GET', '/no-such-page');
            case 'server_error':
                $this->provider()->willCrash();

                return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        }

        return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }
}
