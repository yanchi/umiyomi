<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

enum ForecastStatus
{
    /** 新規取得した予報、または 1 時間以内に取得した予報 */
    case Fresh;

    /** 最新の取得に失敗し、24 時間以内の前回の予報で代替している */
    case Stale;

    case Unavailable;

    case RateLimited;
}
