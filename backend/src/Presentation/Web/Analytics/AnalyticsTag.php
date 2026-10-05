<?php

declare(strict_types=1);

namespace App\Presentation\Web\Analytics;

/**
 * アクセス解析（Google アナリティクス）の測定 ID.
 *
 * 全画面の <head> から使うため Twig の global にする。
 * ID は外部サービスの URL に組み込まれるため、形式に合わない値は無効として扱い、画面に出さない
 */
final readonly class AnalyticsTag
{
    public function __construct(
        private string $measurementId,
    ) {
    }

    public function isEnabled(): bool
    {
        return 1 === preg_match('/^G-[A-Z0-9]{4,20}$/', $this->measurementId);
    }

    public function measurementId(): string
    {
        if (!$this->isEnabled()) {
            throw new \LogicException('Google Analytics is not configured.');
        }

        return $this->measurementId;
    }
}
