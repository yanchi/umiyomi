<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExternalTransmissionPageTest extends WebTestCase
{
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    /** @var array{env: mixed, server: mixed} */
    private array $original = ['env' => null, 'server' => null];

    private bool $overridden = false;

    public static function setUpBeforeClass(): void
    {
        // 404 の画面（error.html.twig）を debug を切ったカーネルで描画するため、古いキャッシュを残さない
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/test');
    }

    protected function tearDown(): void
    {
        if ($this->overridden) {
            $_ENV['GA_MEASUREMENT_ID'] = $this->original['env'];
            $_SERVER['GA_MEASUREMENT_ID'] = $this->original['server'];
            $this->overridden = false;
        }
        parent::tearDown();
    }

    public function testPageContent(): void
    {
        $client = self::createClient();

        $client->request('GET', '/external-transmission');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('h1', '外部送信について');
        $text = $this->bodyText($client);
        foreach ([
            'Google LLC（Google アナリティクス）',
            '表示した地点の緯度・経度を含みます',
            'お気に入りの保存・削除、現在地ボタン、フィードバックのフォームを開く操作の回数',
            'お気に入りの地点・名前、取得した現在地は送信しません',
            '直前に見ていたページのアドレス（UMIYOMI 内のページはクエリを除きます）',
            'Cookie に保存される、ブラウザを識別するための ID',
            '緯度・経度の入力欄に入力した文字列、フィードバックのフォームに入る内容は送信しません。',
            '広告の配信には利用しません。',
        ] as $expected) {
            self::assertStringContainsString($expected, $text);
        }

        $crawler = $client->getCrawler();
        foreach ([
            'https://policies.google.com/privacy?hl=ja',
            'https://policies.google.com/technologies/partner-sites?hl=ja',
            'https://tools.google.com/dlpage/gaoptout?hl=ja',
        ] as $href) {
            self::assertCount(1, $crawler->filter(\sprintf('a[href="%s"]', $href)), $href);
        }
    }

    public function testNoAssertiveExpressions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/external-transmission');

        $text = $this->bodyText($client);
        foreach (self::ASSERTIVE_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $text);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screens(): iterable
    {
        yield 'home' => ['/'];
        yield 'forecast' => ['/forecast?lat=27.75&lon=129.05'];
        yield 'invalid input' => ['/forecast?lat=abc&lon=1'];
        yield 'feedback' => ['/feedback'];
        yield 'not found' => ['/no-such-page'];
    }

    // どの画面からもたどり着ける（FR-009、SC-006）。案内画面自身では自分へのリンクを出さない
    #[DataProvider('screens')]
    public function testFooterLinkOnEveryScreen(string $path): void
    {
        $client = self::createClient(['debug' => false]);
        $client->catchExceptions(true);

        $crawler = $client->request('GET', $path);

        self::assertCount(1, $crawler->filter('a.site-footer__external-transmission[href="/external-transmission"]'));
    }

    public function testNoFooterLinkOnItself(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/external-transmission');

        self::assertCount(0, $crawler->filter('a.site-footer__external-transmission'));
    }

    public function testUnconfiguredShowsNoSwitch(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/external-transmission');

        self::assertCount(0, $crawler->filter('[data-analytics-optout]'));
        self::assertStringContainsString('この環境では現在、アクセス解析による計測を行っていません。', $this->bodyText($client));
        self::assertCount(0, $crawler->filter('meta[name="umiyomi-analytics"]'));
    }

    public function testConfiguredShowsHiddenSwitch(): void
    {
        $this->configure('G-TEST1234');
        $client = self::createClient();

        $crawler = $client->request('GET', '/external-transmission');

        // 初期は hidden。JavaScript が状態に応じて出す（JavaScript が無効なら出ない）
        self::assertCount(1, $crawler->filter('[data-analytics-optout][hidden]'));
        foreach (['measuring', 'stopped', 'unavailable'] as $state) {
            self::assertCount(1, $crawler->filter(\sprintf('[data-analytics-optout-state="%s"][hidden]', $state)), $state);
        }
        foreach (['stopped', 'resumed'] as $message) {
            self::assertCount(1, $crawler->filter(\sprintf('[data-analytics-optout-message="%s"][role="status"][hidden]', $message)), $message);
        }
        foreach (['stop', 'resume'] as $action) {
            self::assertCount(1, $crawler->filter(\sprintf('button[type="button"][data-analytics-optout-action="%s"][hidden]', $action)), $action);
        }
        self::assertStringContainsString('切り替えはこのブラウザだけに保存され、サーバーには送信しません。', $this->bodyText($client));
        self::assertStringNotContainsString('この環境では現在、アクセス解析による計測を行っていません。', $this->bodyText($client));
        self::assertSame('external_transmission', $crawler->filter('meta[name="umiyomi-analytics"]')->attr('data-screen'));
        self::assertSame('/external-transmission', $crawler->filter('meta[name="umiyomi-analytics"]')->attr('data-path'));
    }

    // 案内画面は予報を取得しない。クエリが付いていても読まず、HTML にも出さない（FR-012）
    public function testQueryIsIgnored(): void
    {
        $client = self::createClient();

        $client->request('GET', '/external-transmission?lat=1&x=<script>');

        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('lat=1', $html);
        self::assertStringNotContainsString('x=', $html);
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);
        self::assertSame(0, $provider->callCount());
    }

    private function configure(string $id): void
    {
        // %env()% は実行時に読まれるので、クライアントを作る前に差し替えれば計測が有効な環境を再現できる
        $this->original = ['env' => $_ENV['GA_MEASUREMENT_ID'] ?? null, 'server' => $_SERVER['GA_MEASUREMENT_ID'] ?? null];
        $this->overridden = true;
        $_ENV['GA_MEASUREMENT_ID'] = $id;
        $_SERVER['GA_MEASUREMENT_ID'] = $id;
    }

    private function bodyText(KernelBrowser $client): string
    {
        return $client->getCrawler()->filter('body')->text(null, true);
    }
}
