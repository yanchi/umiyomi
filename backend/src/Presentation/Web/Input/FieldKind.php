<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

enum FieldKind
{
    case Empty;
    case Single;
    case Pair;
    case Invalid;
}
