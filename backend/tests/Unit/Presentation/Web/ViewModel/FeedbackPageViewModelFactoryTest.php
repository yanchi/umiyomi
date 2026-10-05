<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\ViewModel;

use App\Presentation\Web\Feedback\FeedbackFormLink;
use App\Presentation\Web\Input\FeedbackContext;
use App\Presentation\Web\ViewModel\FeedbackPageViewModelFactory;
use PHPUnit\Framework\TestCase;

final class FeedbackPageViewModelFactoryTest extends TestCase
{
    private const string URL = 'https://forms.example.test/feedback?usp=pp_url';

    private FeedbackPageViewModelFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new FeedbackPageViewModelFactory(new FeedbackFormLink(self::URL, 'entry.1000'));
    }

    public function testNoneGoesBackToHome(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::none());

        self::assertSame('app_home', $viewModel->backRoute);
        self::assertSame([], $viewModel->backParameters);
        self::assertSame('トップ画面に戻る', $viewModel->backLabel);
        self::assertSame(self::URL, $viewModel->formUrl);
        self::assertNull($viewModel->prefillSummary);
        self::assertNull($viewModel->quotedInputs);
    }

    public function testForecastGoesBackToForecast(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::forecast('27.75', '129.05', '2026/10/05 09:00'));

        self::assertSame('app_forecast', $viewModel->backRoute);
        self::assertSame(['lat' => '27.75', 'lon' => '129.05'], $viewModel->backParameters);
        self::assertSame('予報画面に戻る', $viewModel->backLabel);
    }

    public function testRejectedInputGoesBackToInputScreen(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::rejectedInput('北緯二十七度', '129.05'));

        self::assertSame('app_forecast', $viewModel->backRoute);
        self::assertSame(['lat' => '北緯二十七度', 'lon' => '129.05'], $viewModel->backParameters);
        self::assertSame('入力画面に戻る', $viewModel->backLabel);
    }

    public function testForecastPrefillWithLastUpdated(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::forecast('27.75', '129.05', '2026/10/05 09:00'));

        self::assertSame(self::URL.'&entry.1000='.rawurlencode('緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00'), $viewModel->formUrl);
        self::assertSame('緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00 がフォームに入ります。', $viewModel->prefillSummary);
        self::assertNull($viewModel->quotedInputs);
    }

    public function testForecastPrefillWithoutLastUpdated(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::forecast('-27.75', '129.05', null));

        self::assertSame(self::URL.'&entry.1000='.rawurlencode('緯度 -27.75・経度 129.05'), $viewModel->formUrl);
        self::assertSame('緯度 -27.75・経度 129.05 がフォームに入ります。', $viewModel->prefillSummary);
    }

    public function testRejectedInputPrefill(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::rejectedInput('北緯二十七度', '129.05'));

        self::assertSame(self::URL.'&entry.1000='.rawurlencode('受け付けられなかった入力：緯度欄「北緯二十七度」、経度欄「129.05」'), $viewModel->formUrl);
        self::assertNull($viewModel->prefillSummary);
        self::assertSame(['latitude' => '北緯二十七度', 'longitude' => '129.05'], $viewModel->quotedInputs);
    }

    public function testRejectedInputEmptyFieldIsMarked(): void
    {
        $viewModel = $this->factory->create(FeedbackContext::rejectedInput('', 'abc'));

        self::assertSame(self::URL.'&entry.1000='.rawurlencode('受け付けられなかった入力：緯度欄「（空）」、経度欄「abc」'), $viewModel->formUrl);
        self::assertSame(['latitude' => '（空）', 'longitude' => 'abc'], $viewModel->quotedInputs);
    }

    public function testNoneHasNoPrefillField(): void
    {
        self::assertSame(self::URL, $this->factory->create(FeedbackContext::none())->formUrl);
    }
}
