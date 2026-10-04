<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\Clock;

use App\Application\Marine\Port\Clock;
use Symfony\Component\Clock\ClockInterface;

final readonly class SystemClock implements Clock
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
