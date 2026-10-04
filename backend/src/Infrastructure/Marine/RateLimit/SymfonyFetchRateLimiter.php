<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\RateLimit;

use App\Application\Marine\Port\FetchRateLimiter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class SymfonyFetchRateLimiter implements FetchRateLimiter
{
    public function __construct(
        #[Target('provider_fetch.limiter')]
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    public function tryConsume(string $clientKey): bool
    {
        return $this->factory->create($clientKey)->consume(1)->isAccepted();
    }
}
