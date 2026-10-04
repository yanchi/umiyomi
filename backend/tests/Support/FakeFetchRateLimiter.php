<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Marine\Port\FetchRateLimiter;

final class FakeFetchRateLimiter implements FetchRateLimiter
{
    private bool $exceeded = false;

    /** @var list<string> */
    private array $consumed = [];

    public function tryConsume(string $clientKey): bool
    {
        if ($this->exceeded) {
            return false;
        }
        $this->consumed[] = $clientKey;

        return true;
    }

    public function exceed(bool $exceeded = true): void
    {
        $this->exceeded = $exceeded;
    }

    /**
     * @return list<string>
     */
    public function consumed(): array
    {
        return $this->consumed;
    }
}
