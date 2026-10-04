<?php

declare(strict_types=1);

namespace App\Application\Marine\Port;

/**
 * 回数を数える条件（再利用できる予報がないこと）は UseCase しか知らないため、UseCase から呼べる Port として定義する（research R5）.
 */
interface FetchRateLimiter
{
    /**
     * 提供元への新規取得を 1 回分消費する。上限に達していれば false.
     */
    public function tryConsume(string $clientKey): bool;
}
