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
 * 入力欄の補助（現在地・案内・食い違いの案内）の DOM 操作はブラウザで動くため、ここでは Twig が出す属性・文言だけを確認する（research R10）.
 */
final class CoordinateInputMarkupTest extends WebTestCase
{
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'home' => ['home'];
        yield 'forecast' => ['forecast'];
    }

    #[DataProvider('screenProvider')]
    public function testFieldsAreTextInputsWithoutAutoCorrection(string $screen): void
    {
        $crawler = $this->open($screen);

        foreach (['#lat', '#lon'] as $selector) {
            $input = $crawler->filter($selector);
            self::assertCount(1, $input, $selector);
            self::assertSame('text', $input->attr('type'));
            self::assertSame('off', $input->attr('autocomplete'));
            self::assertSame('off', $input->attr('autocapitalize'));
            self::assertSame('off', $input->attr('autocorrect'));
            self::assertSame('false', $input->attr('spellcheck'));
            // 数字だけのキーボードでは、カンマ・度の記号・方角の文字を打てない（FR-020）
            self::assertNull($input->attr('inputmode'));
        }
    }

    private const string GUIDE = '十進数（27.75）のほか、「緯度, 経度」の貼り付け（27.75, 129.05）、度分（27°45.0\'N）、度分秒（27°45\'00"N）で入力できます。南緯・西経はマイナスの値にするか、S・W を付けてください。';

    /**
     * @return iterable<string, array{string}>
     */
    public static function guideScreenProvider(): iterable
    {
        yield 'home' => ['home'];
        yield 'forecast' => ['forecast'];
        yield 'invalid input' => ['invalid'];
    }

    #[DataProvider('guideScreenProvider')]
    public function testAcceptedNotationsAreGuidedBelowTheForm(string $screen): void
    {
        $crawler = $this->open($screen);

        $guide = $crawler->filter('form.coordinate-form .form-hint');
        self::assertCount(1, $guide);
        self::assertSame(self::GUIDE, trim($guide->text()));
        self::assertStringNotContainsString('十進数で入力してください。', $crawler->filter('body')->text());
    }
    // 航海の安全や出航の可否を断定する表現（原則 IV）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    #[DataProvider('screenProvider')]
    public function testFormHasHooksForTheBrowserScript(string $screen): void
    {
        $crawler = $this->open($screen);

        self::assertCount(1, $crawler->filter('form.coordinate-form[data-coordinate-input]'));
        self::assertCount(1, $crawler->filter('#lat[data-coordinate-field="latitude"]'));
        self::assertCount(1, $crawler->filter('#lon[data-coordinate-field="longitude"]'));
    }

    #[DataProvider('screenProvider')]
    public function testCurrentLocationIsHiddenUntilTheScriptShowsIt(string $screen): void
    {
        $crawler = $this->open($screen);

        $region = $crawler->filter('[data-current-location]');
        self::assertCount(1, $region);
        self::assertNotNull($region->attr('hidden'));

        $button = $region->filter('[data-current-location-action="fill"]');
        self::assertCount(1, $button);
        self::assertSame('button', $button->attr('type'));
        self::assertSame('現在地を入力', trim($button->text()));

        $status = $region->filter('[role="status"]');
        self::assertCount(1, $status);
        $messages = [
            'loading' => '現在地を取得しています…',
            'filled' => '現在地を入力しました。「予報を表示」を押すと予報を表示します',
            'denied' => '位置情報の利用が許可されなかったため、現在地を入力できませんでした。緯度・経度を入力すると予報を表示できます',
            'failed' => '現在地を取得できませんでした。もう一度試すか、緯度・経度を入力すると予報を表示できます',
            'edited' => '入力欄が変更されたため、取得した現在地は入力していません',
        ];
        foreach ($messages as $name => $text) {
            $message = $status->filter(\sprintf('[data-current-location-message="%s"]', $name));
            self::assertCount(1, $message, $name);
            self::assertNotNull($message->attr('hidden'), $name);
            self::assertSame($text, trim($message->text()), $name);
        }

        self::assertStringContainsString(
            '現在地が陸地や港の内側の場合、波・うねりの予報が得られないことや、沖の海況と異なることがあります。必要に応じて沖側の地点を入力してください。',
            $region->text(),
        );
    }

    public function testStaleNoticeIsOnlyOnForecastPagesWithAList(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
        $notice = $crawler->filter('[data-coordinate-stale-notice]');
        self::assertCount(1, $notice);
        self::assertNotNull($notice->attr('hidden'));
        self::assertStringContainsString('北緯 27.75° / 東経 129.05°', $notice->text());

        self::assertCount(0, $this->client->request('GET', '/')->filter('[data-coordinate-stale-notice]'));
        self::assertCount(0, $this->client->request('GET', '/forecast?lat=95&lon=129.05')->filter('[data-coordinate-stale-notice]'));
    }

    public function testStaleNoticeIsNotShownWithoutAForecastList(): void
    {
        $this->provider()->willFail();
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertResponseStatusCodeSame(503);
        self::assertCount(0, $crawler->filter('[data-coordinate-stale-notice]'));
    }

    public function testStaleNoticeIsNotShownWhenRateLimited(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', \sprintf('/forecast?lat=%.2f&lon=120.00', 10 + $i));
        }
        $crawler = $this->client->request('GET', '/forecast?lat=45.00&lon=129.05');

        self::assertResponseStatusCodeSame(429);
        self::assertCount(0, $crawler->filter('[data-coordinate-stale-notice]'));
    }

    public function testSavePanelShowsTheLocationThatWillBeSaved(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertStringContainsString(
            '保存される地点：北緯 27.75° / 東経 129.05°（表示中の地点）',
            preg_replace('/\s+/', ' ', $crawler->filter('[data-favorites="save-panel"]')->text()) ?? '',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allScreenProvider(): iterable
    {
        yield 'home' => ['home'];
        yield 'forecast' => ['forecast'];
        yield 'invalid input' => ['invalid'];
    }

    #[DataProvider('allScreenProvider')]
    public function testNoAssertiveSafetyPhrases(string $screen): void
    {
        $text = $this->open($screen)->filter('body')->text();

        foreach (self::ASSERTIVE_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $text);
        }
    }

    private function provider(): FakeMarineForecastProvider
    {
        $provider = self::getContainer()->get(FakeMarineForecastProvider::class);
        \assert($provider instanceof FakeMarineForecastProvider);

        return $provider;
    }

    private function open(string $screen): Crawler
    {
        return match ($screen) {
            'home' => $this->client->request('GET', '/'),
            'invalid' => $this->client->request('GET', '/forecast?lat=95&lon=129.05'),
            default => $this->client->request('GET', '/forecast?lat=27.75&lon=129.05'),
        };
    }
}
