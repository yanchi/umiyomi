<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 方角の文字（N・S・E・W）から決まる軸.
 */
enum Axis
{
    case Latitude;
    case Longitude;
}
