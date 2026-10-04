<?php

declare(strict_types=1);

namespace App\Application\Marine\Port;

/**
 * Application は Deptrac で Psr\ を参照できないため、PSR-20 の ClockInterface ではなく自前の Port を定義する.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
