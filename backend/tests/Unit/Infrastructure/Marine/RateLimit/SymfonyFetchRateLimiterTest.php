<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\RateLimit;

use App\Infrastructure\Marine\RateLimit\SymfonyFetchRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SymfonyFetchRateLimiterTest extends TestCase
{
    private SymfonyFetchRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new SymfonyFetchRateLimiter(new RateLimiterFactory(
            ['id' => 'provider_fetch', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '10 minutes'],
            new InMemoryStorage(),
        ));
    }

    public function testAllowsUpToLimit(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            self::assertTrue($this->limiter->tryConsume('192.0.2.1'), \sprintf('Request %d should be accepted.', $i));
        }

        self::assertFalse($this->limiter->tryConsume('192.0.2.1'));
    }

    public function testCountsEachClientIndependently(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->limiter->tryConsume('192.0.2.1');
        }

        self::assertFalse($this->limiter->tryConsume('192.0.2.1'));
        self::assertTrue($this->limiter->tryConsume('192.0.2.2'));
    }
}
