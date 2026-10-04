<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Marine\Port\Clock;

final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-05T11:15:00Z')
    {
        $this->setNow(new \DateTimeImmutable($now));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param string $modifier DateTimeImmutable::modify() の書式（例 '+59 minutes'）
     */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
