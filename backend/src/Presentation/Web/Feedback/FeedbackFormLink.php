<?php

declare(strict_types=1);

namespace App\Presentation\Web\Feedback;

/**
 * 運営者が用意した外部フォームの URL と、事前入力付きの URL の組み立て.
 *
 * 全画面のフッターから使うため Twig の global にする。フォームの内容は UMIYOMI が受け取らない
 */
final readonly class FeedbackFormLink
{
    public function __construct(
        private string $formUrl,
        private string $prefillField,
    ) {
    }

    // 片方だけの中途半端な設定でリンクを出さない。http や # 付きは、Query を足したときに意図しない先へ向かう恐れがあるため除く
    public function isAvailable(): bool
    {
        return '' !== $this->formUrl
            && '' !== $this->prefillField
            && str_starts_with($this->formUrl, 'https://')
            && !str_contains($this->formUrl, '#');
    }

    public function urlFor(?string $prefill): string
    {
        if (!$this->isAvailable()) {
            throw new \LogicException('The feedback form is not configured.');
        }
        if (null === $prefill) {
            return $this->formUrl;
        }

        // parse_str / http_build_query は欄の名前の . を _ に変える（entry.123 → entry_123）ため事前入力が効かなくなる。
        // 運営者が付けた Query（usp=pp_url など）をそのまま残すためにも、文字列で末尾に足す
        $separator = str_contains($this->formUrl, '?') ? '&' : '?';

        return $this->formUrl.$separator.rawurlencode($this->prefillField).'='.rawurlencode($prefill);
    }
}
