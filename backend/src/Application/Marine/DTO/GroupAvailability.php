<?php

declare(strict_types=1);

namespace App\Application\Marine\DTO;

/**
 * Presentation が Domain の Availability を参照せずに済むよう、Application 側に写したもの.
 */
enum GroupAvailability
{
    case Available;
    case NotProvidedAtLocation;
    case FetchFailed;
}
