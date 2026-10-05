<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeMarineForecastProvider;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * お気に入りの DOM 操作はブラウザで動くため、ここでは Twig が出す枠・data 属性・文言だけを確認する（research R11）.
 */
final class FavoritesMarkupTest extends WebTestCase
{
    // 航海の安全や出航の可否を断定する表現（FR-013）
    private const array ASSERTIVE_PHRASES = ['安全です', '出航できます', '問題ありません'];

    private const string STORAGE_NOTE = 'お気に入りはこの端末のこのブラウザにだけ保存され、他の端末とは共有されません。ブラウザのデータを消去すると消えます。';
    private const string UNAVAILABLE = 'この環境ではお気に入りを保存・表示できません。緯度・経度を入力すれば予報は表示できます。';
    private const string EMPTY = 'お気に入りはまだありません。予報画面で表示中の地点を保存できます。';

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

    public function testHomeShowsManageListAfterForm(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('[data-favorites="manage-list"]');
        self::assertCount(1, $frame);
        self::assertSame('お気に入り', $frame->filter('h2')->text());
        self::assertStringContainsString(self::STORAGE_NOTE, $frame->text());
        $this->assertHiddenState($frame, 'empty', self::EMPTY);
        $this->assertHiddenState($frame, 'unavailable', self::UNAVAILABLE);
        self::assertSame(self::UNAVAILABLE, $this->noscriptText());
        self::assertNotNull($frame->filter('[data-favorites-state="ready"]')->attr('hidden'));
        self::assertNotNull($frame->filter('[data-favorites-message="write_failed"]')->attr('hidden'));
        self::assertOrder('class="coordinate-form"', 'data-favorites="manage-list"');
    }

    public function testManageItemTemplateHasLinkWithNameAndCoordinate(): void
    {
        $this->client->request('GET', '/');

        $item = $this->template('manage-item');
        self::assertCount(1, $item->filter('li a[href]'));
        self::assertCount(1, $item->filter('a [data-favorites-field="name"]'));
        self::assertCount(1, $item->filter('a [data-favorites-field="coordinate"]'));
    }

    public function testForecastPageShowsSwitchListBetweenFormAndForecast(): void
    {
        // 丸める前の値で開き、リダイレクト先（正規形の URL）の予報画面を確かめる
        $this->client->request('GET', '/forecast?lat=27.7549&lon=129.0501');
        $crawler = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        $details = $crawler->filter('details.favorites-switch');
        self::assertCount(1, $details);
        self::assertNull($details->attr('open'));
        self::assertCount(1, $details->filter('summary [data-favorites="count"]'));
        self::assertStringContainsString('お気に入り（', $details->filter('summary')->text());
        $frame = $details->filter('[data-favorites="switch-list"]');
        self::assertCount(1, $frame);
        self::assertStringContainsString(self::STORAGE_NOTE, $frame->text());
        $this->assertHiddenState($frame, 'empty', self::EMPTY);
        $this->assertHiddenState($frame, 'unavailable', self::UNAVAILABLE);
        self::assertCount(1, $this->template('switch-item')->filter('li a[href]'));
        self::assertOrder('class="coordinate-form"', 'class="favorites-switch"');
        self::assertOrder('class="favorites-switch"', 'class="last-updated"');
    }

    public function testForecastPageShowsSavePanelAfterLocation(): void
    {
        // 丸める前の値で開き、リダイレクト先（正規形の URL）の予報画面を確かめる
        $this->client->request('GET', '/forecast?lat=27.7549&lon=129.0501');
        $crawler = $this->client->followRedirect();

        $panel = $crawler->filter('[data-favorites="save-panel"][data-latitude="27.75"][data-longitude="129.05"]');
        self::assertCount(1, $panel);
        self::assertSame('名前（省略可・30 文字以内）', trim($panel->filter('label')->text()));

        $input = $panel->filter('input[type="text"]');
        self::assertCount(1, $input);
        self::assertSame($input->attr('id'), $panel->filter('label')->attr('for'));
        self::assertSame('例：27.75, 129.05', $input->attr('placeholder'));
        // maxlength は貼り付けた文字列を黙って切り詰めるため付けない（research R4）
        self::assertNull($input->attr('maxlength'));
        self::assertSame('お気に入りに保存', trim($panel->filter('button[type="submit"]')->text()));

        $this->assertHiddenState($panel, 'unsaved');
        $this->assertHiddenState($panel, 'saved');
        $this->assertHiddenState($panel, 'unavailable', self::UNAVAILABLE);
        self::assertCount(1, $panel->filter('[role="status"]'));
        foreach (['saved', 'limit', 'write_failed'] as $message) {
            self::assertCount(1, $panel->filter('[role="status"] [data-favorites-message="'.$message.'"]'), $message);
            self::assertNotNull($panel->filter('[data-favorites-message="'.$message.'"]')->attr('hidden'), $message);
        }
        self::assertSame('お気に入りに保存しました', trim($panel->filter('[data-favorites-message="saved"]')->text()));
        self::assertSame('名前は 30 文字以内で入力してください', trim($panel->filter('[data-favorites-message="name_too_long"]')->text()));
        self::assertSame($panel->filter('[data-favorites-message="name_too_long"]')->attr('id'), $input->attr('aria-describedby'));
        self::assertStringContainsString('お気に入りは 20 件まで保存できます。', $panel->filter('[data-favorites-message="limit"]')->text());
        self::assertStringContainsString('お気に入りを保存できませんでした。', $panel->filter('[data-favorites-message="write_failed"]')->text());
        self::assertStringContainsString('お気に入りに保存済み：', $panel->filter('[data-favorites-state="saved"]')->text());
        self::assertCount(1, $panel->filter('[data-favorites-state="saved"] [data-favorites-field="name"]'));

        self::assertOrder('class="last-updated"', 'data-favorites="save-panel"');
        self::assertOrder('data-favorites="save-panel"', 'class="disclaimer"');
    }

    public function testManageListHasRemoveButtonAndConfirmationTemplate(): void
    {
        $crawler = $this->client->request('GET', '/');

        $frame = $crawler->filter('[data-favorites="manage-list"]');
        self::assertSame('『{name}』をお気に入りから削除しますか？', $frame->attr('data-confirm-remove'));
        $button = $this->template('manage-item')->filter('button[data-favorites-action="remove"]');
        self::assertCount(1, $button);
        self::assertSame('button', $button->attr('type'));
        self::assertSame('削除', trim($button->text()));
    }

    public function testManageItemHasInlineRenameForm(): void
    {
        $this->client->request('GET', '/');

        $item = $this->template('manage-item');
        $rename = $item->filter('button[data-favorites-action="rename"]');
        self::assertCount(1, $rename);
        self::assertSame('button', $rename->attr('type'));
        self::assertSame('名前を変更', trim($rename->text()));

        $form = $item->filter('form');
        self::assertCount(1, $form);
        self::assertNotNull($form->attr('hidden'));
        self::assertSame('名前（省略可・30 文字以内）', trim($form->filter('label')->text()));
        $input = $form->filter('label input[type="text"]');
        self::assertCount(1, $input);
        // maxlength は貼り付けた文字列を黙って切り詰めるため付けない（research R4）
        self::assertNull($input->attr('maxlength'));
        self::assertSame('保存', trim($form->filter('button[type="submit"]')->text()));
        $cancel = $form->filter('button[data-favorites-action="cancel-rename"]');
        self::assertSame('button', $cancel->attr('type'));
        self::assertSame('キャンセル', trim($cancel->text()));
        $message = $form->filter('[data-favorites-message="name_too_long"]');
        self::assertNotNull($message->attr('hidden'));
        self::assertSame('名前は 30 文字以内で入力してください', trim($message->text()));
        self::assertNotNull($input->attr('aria-describedby'));
    }

    public function testSavePanelHasRemoveButtonAndRemovedMessage(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        $panel = $crawler->filter('[data-favorites="save-panel"]');
        self::assertSame('『{name}』をお気に入りから削除しますか？', $panel->attr('data-confirm-remove'));
        $button = $panel->filter('[data-favorites-state="saved"] button[data-favorites-action="remove"]');
        self::assertCount(1, $button);
        self::assertSame('button', $button->attr('type'));
        self::assertSame('お気に入りから外す', trim($button->text()));
        $removed = $panel->filter('[role="status"] [data-favorites-message="removed"]');
        self::assertCount(1, $removed);
        self::assertNotNull($removed->attr('hidden'));
        self::assertSame('お気に入りから削除しました', trim($removed->text()));
    }

    // 予報画面の一覧は呼び出し専用にして、誤操作を防ぐ（FR-016）
    public function testSwitchItemHasNoManagementButtons(): void
    {
        $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');

        self::assertCount(0, $this->template('switch-item')->filter('button, form, input'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonFreshScreenProvider(): iterable
    {
        yield 'stale' => ['stale'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limited' => ['rate_limited'];
    }

    #[DataProvider('nonFreshScreenProvider')]
    public function testSavePanelIsShownEvenWithoutFreshForecast(string $screen): void
    {
        $crawler = $this->open($screen);

        self::assertCount(1, $crawler->filter('[data-favorites="save-panel"][data-latitude="27.75"][data-longitude="129.05"]'));
        self::assertCount(1, $crawler->filter('details.favorites-switch'));
    }

    public function testInvalidInputHidesSavePanelButKeepsSwitchList(): void
    {
        $crawler = $this->client->request('GET', '/forecast?lat=95&lon=129.05');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('[data-favorites="save-panel"]'));
        self::assertCount(1, $crawler->filter('details.favorites-switch [data-favorites="switch-list"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'home' => ['home'];
        yield 'fresh' => ['fresh'];
        yield 'stale' => ['stale'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limited' => ['rate_limited'];
        yield 'invalid input' => ['invalid_input'];
    }

    #[DataProvider('screenProvider')]
    public function testScreensDoNotAssertSafety(string $screen): void
    {
        $this->open($screen);

        foreach (self::ASSERTIVE_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $this->html());
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
                return $this->client->request('GET', '/forecast?lat=95&lon=129.05');
        }

        return $this->client->request('GET', '/forecast?lat=27.75&lon=129.05');
    }

    /**
     * hidden 付きで出力され、JavaScript が表示を切り替える状態の要素.
     */
    private function assertHiddenState(Crawler $frame, string $state, ?string $text = null): void
    {
        $element = $frame->filter('[data-favorites-state="'.$state.'"]');
        self::assertCount(1, $element, $state);
        self::assertNotNull($element->attr('hidden'), $state);
        if (null !== $text) {
            self::assertStringContainsString($text, $element->text(), $state);
        }
    }

    /**
     * DomCrawler は <template> の中身を辿れないため、HTML から取り出して別のクローラーで読む.
     */
    private function template(string $name): Crawler
    {
        $found = preg_match('#<template data-favorites-template="'.preg_quote($name, '#').'">(.*?)</template>#s', $this->html(), $matches);
        self::assertSame(1, $found, \sprintf('template %s がない', $name));

        return new Crawler($matches[1]);
    }

    private function noscriptText(): string
    {
        self::assertSame(1, preg_match('#<noscript>(.*?)</noscript>#s', $this->html(), $matches));

        return $this->normalize(strip_tags($matches[1]));
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function assertOrder(string $earlier, string $later): void
    {
        $html = $this->html();
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

    private function clock(): FixedClock
    {
        $clock = self::getContainer()->get(FixedClock::class);
        \assert($clock instanceof FixedClock);

        return $clock;
    }
}
