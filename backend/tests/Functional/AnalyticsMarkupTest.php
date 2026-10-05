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
 * 計測が有効な環境で、全画面の <head> に出す計測の設定（画面の種類とアドレス）の検査.
 *
 * 404 は TwigBundle の error.html.twig で描画されることを前提にするため、debug を切ったカーネルで動かす
 */
final class AnalyticsMarkupTest extends WebTestCase
{
    private const string MEASUREMENT_ID = 'G-TEST1234';

    private const array INPUTS = [
        '"><script>alert(1)</script>',
        "'; DROP",
        '北緯二十七度',
    ];

    /** @var array{env: mixed, server: mixed} */
    private array $original = ['env' => null, 'server' => null];

    private KernelBrowser $client;

    // debug を切ったカーネルは設定のキャッシュが古くなっても作り直さないため、クラスの開始時に消す
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/test');
    }

    protected function setUp(): void
    {
        // %env()% は実行時に読まれるので、クライアントを作る前に差し替えれば計測が有効な環境を再現できる
        $this->original = [
            'env' => $_ENV['GA_MEASUREMENT_ID'] ?? null,
            'server' => $_SERVER['GA_MEASUREMENT_ID'] ?? null,
        ];
        $_ENV['GA_MEASUREMENT_ID'] = self::MEASUREMENT_ID;
        $_SERVER['GA_MEASUREMENT_ID'] = self::MEASUREMENT_ID;

        $this->client = self::createClient(['debug' => false]);
        $this->client->disableReboot();
        $this->client->catchExceptions(true);

        foreach (['cache.marine_forecast', 'cache.rate_limiter'] as $pool) {
            $service = self::getContainer()->get($pool);
            \assert($service instanceof CacheItemPoolInterface);
            $service->clear();
        }
    }

    protected function tearDown(): void
    {
        $_ENV['GA_MEASUREMENT_ID'] = $this->original['env'];
        $_SERVER['GA_MEASUREMENT_ID'] = $this->original['server'];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function screens(): iterable
    {
        yield 'home' => ['home', 'home', '/'];
        yield 'forecast 200' => ['forecast', 'forecast', '/forecast?lat=27.75&lon=129.05'];
        yield 'forecast 503' => ['unavailable', 'forecast_unavailable', '/forecast?lat=27.75&lon=129.05'];
        yield 'forecast 429' => ['rate_limited', 'rate_limited', '/forecast?lat=45.00&lon=129.05'];
        yield 'forecast 422' => ['invalid_input', 'invalid_input', '/forecast'];
        yield 'feedback' => ['feedback', 'feedback', '/feedback'];
        yield 'feedback with context' => ['feedback_context', 'feedback', '/feedback'];
        yield 'external transmission' => ['external_transmission', 'external_transmission', '/external-transmission'];
        yield 'feedback with input' => ['feedback_input', 'feedback', '/feedback'];
        yield 'not found' => ['not_found', 'error', '/error'];
    }

    #[DataProvider('screens')]
    public function testMetaOnEveryScreen(string $screen, string $expectedScreen, string $expectedPath): void
    {
        $crawler = $this->open($screen);

        $meta = $crawler->filter('head meta[name="umiyomi-analytics"]');
        self::assertCount(1, $meta);
        self::assertSame(self::MEASUREMENT_ID, $meta->attr('data-measurement-id'));
        self::assertSame($expectedScreen, $meta->attr('data-screen'));
        self::assertSame($expectedPath, $meta->attr('data-path'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inputs(): iterable
    {
        foreach (self::INPUTS as $input) {
            yield $input => [$input];
        }
        yield '500 characters' => [str_repeat('あ', 500)];
    }

    // 入力不正・案内画面のアドレスに、利用者の入力文字列を載せない（FR-005）
    #[DataProvider('inputs')]
    public function testInputIsNeverSent(string $input): void
    {
        $queries = [
            '/forecast?'.http_build_query(['lat' => $input, 'lon' => $input]),
            '/feedback?'.http_build_query(['input_lat' => $input, 'input_lon' => $input, 'updated' => $input]),
        ];

        foreach ($queries as $query) {
            $crawler = $this->client->request('GET', $query);
            $meta = $crawler->filter('head meta[name="umiyomi-analytics"]');
            self::assertCount(1, $meta, $query);
            $path = (string) $meta->attr('data-path');
            self::assertStringNotContainsString($input, $path);
            self::assertStringNotContainsString(rawurlencode($input), $path);
            self::assertStringNotContainsString('script', $path);
            self::assertSame('/', $path[0]);
            self::assertStringNotContainsString('?', $path, $query);
        }
    }

    // 404 のパスは利用者が自由に付けられるので、アドレスに載せない（FR-005）
    public function testErrorScreenNeverSendsRequestedPath(): void
    {
        foreach (self::INPUTS as $input) {
            $crawler = $this->client->request('GET', '/'.rawurlencode($input));
            $meta = $crawler->filter('head meta[name="umiyomi-analytics"]');
            self::assertCount(1, $meta);
            self::assertSame('/error', $meta->attr('data-path'));
        }
    }

    // Google のタグの読み込みはブラウザの JavaScript が行い、HTML には書かない
    #[DataProvider('screens')]
    public function testNoGoogleScriptInHtml(string $screen, string ...$expected): void
    {
        $this->open($screen);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('googletagmanager', $html);
        self::assertStringNotContainsString('gtag(', $html);
    }

    public function testNoExternalApiCallExceptOnForecast(): void
    {
        foreach (['home', 'invalid_input', 'feedback', 'feedback_context', 'feedback_input', 'not_found'] as $screen) {
            $this->open($screen);
        }

        self::assertSame(0, $this->provider()->callCount());
    }

    private function open(string $screen): Crawler
    {
        switch ($screen) {
            case 'home':
                return $this->client->request('GET', '/');
            case 'invalid_input':
                return $this->client->request('GET', '/forecast?lat=北緯二十七度&lon=129.05');
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
            case 'feedback_context':
                return $this->client->request('GET', '/feedback?lat=27.75&lon=129.05&updated=2026/10/05 09:00');
            case 'feedback_input':
                return $this->client->request('GET', '/feedback?input_lat=<script>&input_lon=abc');
            case 'external_transmission':
                return $this->client->request('GET', '/external-transmission');
            case 'not_found':
                return $this->client->request('GET', '/no-such-page?x=1');
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
