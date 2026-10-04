<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 風・波・うねりのどちらも取得できなかったことを表す。メッセージは原因の調査（ログ）用で、画面には出さない.
 */
final class FetchFailure extends \RuntimeException
{
}
