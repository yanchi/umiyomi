<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 項目グループ（風 / 波・うねり）ごとの取得状況。グループ単位で持つのは、片方の API だけ失敗しても
 * 取得できた項目は表示するため（research R3）.
 */
enum Availability
{
    /** 取得できた（一部の時刻が欠けていてもよい） */
    case Available;

    /** 取得はできたが全時刻の値が null（内陸など） */
    case NotProvidedAtLocation;

    case FetchFailed;
}
