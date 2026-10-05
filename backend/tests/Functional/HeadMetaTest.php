<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 全画面の <head>（アイコン・OG・説明文・robots）の検査。
 *
 * 404・500 は TwigBundle の error.html.twig（本番相当の画面）で描画されることを前提にするため、
 * debug を切ったカーネルで動かす。WebTestCase は既定で例外を再スローするので、
 * catchExceptions(true) でエラー画面まで描画させる。
 */
final class HeadMetaTest extends WebTestCase
{
    private const string COMMON_DESCRIPTION = '緯度・経度を入力すると、風・波・うねりの時間別予報を確認できます。出航の判断には、気象庁などの警報・注意報も確認してください。';
    private const string IMAGE_ALT = 'UMIYOMI のロゴ。風・波・うねりの予報を出航前に確認するサービス';

    // 航海の安全や出航の可否を断定する表現（FR-008、SC-004）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // debug だと 404・500 が開発用の例外画面になり、本番で出る error.html.twig を検査できない
        $this->client = self::createClient(['debug' => false]);
        $this->client->disableReboot();

        foreach (['cache.marine_forecast', 'cache.rate_limiter'] as $pool) {
            $service = self::getContainer()->get($pool);
            \assert($service instanceof CacheItemPoolInterface);
            $service->clear();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screens(): iterable
    {
        yield 'home' => ['home'];
        yield 'forecast 200' => ['forecast'];
        yield 'forecast 422' => ['invalid_input'];
        yield 'forecast 429' => ['rate_limited'];
        yield 'forecast 503' => ['unavailable'];
        yield 'feedback' => ['feedback'];
        yield 'not found' => ['not_found'];
        yield 'server error' => ['server_error'];
    }

    #[DataProvider('screens')]
    public function testIconLinksOnEveryScreen(string $screen): void
    {
        $crawler = $this->open($screen);

        $ico = $crawler->filter('head link[rel="icon"][href="/favicon.ico"][sizes="32x32"]');
        self::assertCount(1, $ico);
        self::assertCount(1, $crawler->filter('head link[rel="icon"][type="image/svg+xml"]'));
        self::assertCount(1, $crawler->filter('head link[rel="icon"][type="image/png"][sizes="192x192"]'));
        self::assertCount(1, $crawler->filter('head link[rel="apple-touch-icon"]'));
        self::assertCount(0, $crawler->filter('head link[rel="manifest"]'));
        self::assertCount(0, $crawler->filter('head meta[name="theme-color"]'));
    }

    // AssetMapper が URL を返すのは debug のときだけ（本番は compile 済みの静的ファイル）なので、ここだけ debug のカーネルを使う。本番イメージでの配信は scripts/verify-prod.sh が確かめる
    public function testIconUrlsAreServedWithoutCallingProvider(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $crawler = $this->client->request('GET', '/');

        $urls = [
            'image/svg+xml' => (string) $crawler->filter('head link[rel="icon"][type="image/svg+xml"]')->attr('href'),
            'image/png' => (string) $crawler->filter('head link[rel="icon"][type="image/png"]')->attr('href'),
        ];
        $urls['apple'] = (string) $crawler->filter('head link[rel="apple-touch-icon"]')->attr('href');
        $urls['og'] = (string) preg_replace('#^https?://[^/]+#', '', (string) $crawler->filter('head meta[property="og:image"]')->attr('content'));

        foreach ($urls as $type => $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(200, $url);
            $expected = 'image/svg+xml' === $type ? 'image/svg+xml' : 'image/png';
            self::assertStringStartsWith($expected, (string) $this->client->getResponse()->headers->get('Content-Type'), $url);
        }

        self::assertSame(0, $this->provider()->callCount());
    }

    // /favicon.ico は public/ の静的ファイルで WebTestCase（Kernel 経由）では返らない。HTTP での配信は scripts/verify-prod.sh が確かめる
    public function testFaviconIcoContainsSixteenAndThirtyTwoPixelEntries(): void
    {
        $path = \dirname(__DIR__, 2).'/public/favicon.ico';
        self::assertFileExists($path);

        $bytes = (string) file_get_contents($path);
        $header = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));
        self::assertIsArray($header);
        self::assertSame(0, $header['reserved']);
        self::assertSame(1, $header['type']);
        self::assertSame(2, $header['count']);

        $widths = [];
        for ($i = 0; $i < 2; ++$i) {
            $widths[] = \ord($bytes[6 + $i * 16]);
        }
        sort($widths);
        self::assertSame([16, 32], $widths);
    }

    public function testIconImageDimensions(): void
    {
        $images = \dirname(__DIR__, 2).'/assets/images';

        self::assertSame([192, 192], $this->dimensions($images.'/icon-192.png'));
        self::assertSame([180, 180], $this->dimensions($images.'/apple-touch-icon.png'));

        // iOS は透過部分を黒で塗るので、全面が不透明であること（FR-002）。GD が入っていないため PNG を直接読む。
        // 先頭の画素は、どのフィルタでも直前の画素・上の行がないので生のバイトがそのまま画素になる
        $bytes = (string) file_get_contents($images.'/apple-touch-icon.png');
        $colorType = \ord($bytes[25]);
        if (6 === $colorType) {
            $data = (string) gzuncompress($this->idatData($bytes));
            // 先頭 1 バイトは行のフィルタ種別、続く 4 バイトが RGBA
            self::assertSame(255, \ord($data[4]), '左上の画素が透過している');
        } else {
            self::assertNotContains($colorType, [4], 'アルファ付きのグレースケール');
        }
    }

    public function testOgImageDimensionsAndSize(): void
    {
        $path = \dirname(__DIR__, 2).'/assets/images/og-image.png';

        self::assertSame([1200, 630], $this->dimensions($path));
        self::assertLessThan(300 * 1024, (int) filesize($path));
    }

    #[DataProvider('screens')]
    public function testOpenGraphOnEveryScreen(string $screen): void
    {
        $crawler = $this->open($screen);

        $title = trim($crawler->filter('head title')->text());
        $description = (string) $crawler->filter('head meta[name="description"]')->attr('content');

        self::assertSame('UMIYOMI', $this->meta($crawler, 'og:site_name'));
        self::assertSame('website', $this->meta($crawler, 'og:type'));
        self::assertSame('ja_JP', $this->meta($crawler, 'og:locale'));
        self::assertSame($title, $this->meta($crawler, 'og:title'));
        self::assertSame($description, $this->meta($crawler, 'og:description'));
        self::assertSame('1200', $this->meta($crawler, 'og:image:width'));
        self::assertSame('630', $this->meta($crawler, 'og:image:height'));
        self::assertSame(self::IMAGE_ALT, $this->meta($crawler, 'og:image:alt'));
        self::assertSame('summary_large_image', (string) $crawler->filter('head meta[name="twitter:card"]')->attr('content'));

        // 完全なアドレスで、site_origin と asset() の連結で「//」や欠落が出ない（FR-006）
        $image = $this->meta($crawler, 'og:image');
        self::assertMatchesRegularExpression('#^http://localhost/assets/images/og-image-[^/]+\.png$#', $image);

        self::assertCount(0, $crawler->filter('head meta[property="og:url"]'));
    }

    #[DataProvider('screens')]
    public function testNoAssertivePhrasesInHead(string $screen): void
    {
        $crawler = $this->open($screen);

        $values = [
            $crawler->filter('head title')->text(),
            (string) $crawler->filter('head meta[name="description"]')->attr('content'),
        ];
        foreach ($crawler->filter('head meta[property^="og:"]')->each(static fn (Crawler $meta): ?string => $meta->attr('content')) as $content) {
            $values[] = (string) $content;
        }

        foreach ($values as $value) {
            foreach (self::ASSERTIVE_PHRASES as $phrase) {
                self::assertStringNotContainsString($phrase, $value);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileInputs(): iterable
    {
        yield 'script' => ['/forecast?lat=<script>alert(1)</script>&lon=129.05'];
        yield 'quotes' => ['/forecast?lat=%22%27onerror%3Dx&lon=%27%22'];
        yield 'long' => ['/forecast?lat='.str_repeat('A', 150).'&lon=129.05'];
        yield 'long lon' => ['/forecast?lat=27.75&lon='.str_repeat('B', 150)];
    }

    #[DataProvider('hostileInputs')]
    public function testUserInputNeverAppearsInHead(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        foreach (['alert(1)', 'onerror', str_repeat('A', 20), str_repeat('B', 20)] as $needle) {
            self::assertStringNotContainsString($needle, $head);
        }
        self::assertStringContainsString('<title>予報 | UMIYOMI</title>', $head);
    }

    public function testHomeMeta(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('UMIYOMI｜風・波・うねりの予報を出航前に確認', trim($crawler->filter('head title')->text()));
        self::assertSame(self::COMMON_DESCRIPTION, $crawler->filter('head meta[name="description"]')->attr('content'));
        // 登録を許可するのはトップだけ（FR-011）
        self::assertCount(0, $crawler->filter('head meta[name="robots"]'));
    }

    public function testForecastMeta(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('北緯 27.75° / 東経 129.05° | UMIYOMI', trim($crawler->filter('head title')->text()));
        self::assertSame(self::COMMON_DESCRIPTION, $crawler->filter('head meta[name="description"]')->attr('content'));
        self::assertSame('noindex', $crawler->filter('head meta[name="robots"]')->attr('content'));
        $this->assertNoForecastValuesInHead($crawler);
    }

    public function testInvalidInputMeta(): void
    {
        $crawler = $this->open('invalid_input');

        self::assertSame('予報 | UMIYOMI', trim($crawler->filter('head title')->text()));
        self::assertSame('noindex', $crawler->filter('head meta[name="robots"]')->attr('content'));
    }

    public function testRateLimitedMeta(): void
    {
        // 429・503 は予報を作れないので page.location が空で、タイトルは地点を含まない
        $crawler = $this->open('rate_limited');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('noindex', $crawler->filter('head meta[name="robots"]')->attr('content'));
        self::assertSame('予報 | UMIYOMI', trim($crawler->filter('head title')->text()));
        $this->assertNoForecastValuesInHead($crawler);
    }

    public function testUnavailableMeta(): void
    {
        $crawler = $this->open('unavailable');
        self::assertResponseStatusCodeSame(503);
        self::assertSame('noindex', $crawler->filter('head meta[name="robots"]')->attr('content'));
        self::assertSame('予報 | UMIYOMI', trim($crawler->filter('head title')->text()));
        $this->assertNoForecastValuesInHead($crawler);
    }

    public function testFeedbackMeta(): void
    {
        $crawler = $this->open('feedback');

        self::assertSame('フィードバック | UMIYOMI', trim($crawler->filter('head title')->text()));
        self::assertSame('UMIYOMI へのご意見・ご要望の案内です。', $crawler->filter('head meta[name="description"]')->attr('content'));
        self::assertCount(1, $crawler->filter('head meta[name="robots"][content="noindex"]'));
        self::assertCount(1, $crawler->filter('head meta[name="referrer"][content="no-referrer"]'));
    }

    #[DataProvider('errorScreens')]
    public function testErrorScreenMeta(string $screen, int $status): void
    {
        $crawler = $this->open($screen);

        self::assertResponseStatusCodeSame($status);
        self::assertSame('エラー | UMIYOMI', trim($crawler->filter('head title')->text()));
        self::assertSame(self::COMMON_DESCRIPTION, $crawler->filter('head meta[name="description"]')->attr('content'));
        self::assertCount(1, $crawler->filter('head meta[name="robots"][content="noindex"]'));

        // 例外メッセージ・入力・スタックトレースを出さない（FR-010）
        $body = $crawler->filter('body')->text();
        foreach (['Exception', 'Fake provider', 'Stack trace', 'No route found', 'alert('] as $needle) {
            self::assertStringNotContainsString($needle, $body);
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function errorScreens(): iterable
    {
        yield 'not found' => ['not_found', 404];
        yield 'server error' => ['server_error', 500];
    }

    private function open(string $screen): Crawler
    {
        $this->client->catchExceptions(true);

        switch ($screen) {
            case 'home':
                return $this->client->request('GET', '/');
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
            case 'not_found':
                return $this->client->request('GET', '/no-such-page?lat=<script>alert(1)</script>');
            case 'server_error':
                $this->provider()->willCrash();

                return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        }

        return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
    }

    private function meta(Crawler $crawler, string $property): string
    {
        $node = $crawler->filter(\sprintf('head meta[property="%s"]', $property));
        self::assertCount(1, $node, $property);

        return (string) $node->attr('content');
    }

    // 共有用情報は取得時点の古い値が長く残るので、予報の数値・最終更新日時を載せない（FR-007）
    private function assertNoForecastValuesInHead(Crawler $crawler): void
    {
        $head = $crawler->filter('head')->html();
        self::assertStringNotContainsString('最終更新', $head);
        self::assertDoesNotMatchRegularExpression('#\d+(\.\d+)?\s*(m/s|m\b)#', $head);
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $path): array
    {
        self::assertFileExists($path);
        $size = getimagesize($path);
        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }

    private function idatData(string $png): string
    {
        $data = '';
        $offset = 8;
        while ($offset < \strlen($png)) {
            $unpacked = unpack('Nlength', substr($png, $offset, 4));
            \assert(\is_array($unpacked) && \is_int($unpacked['length']));
            $length = $unpacked['length'];
            if ('IDAT' === substr($png, $offset + 4, 4)) {
                $data .= substr($png, $offset + 8, $length);
            }
            $offset += 12 + $length;
        }

        return $data;
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }
}
