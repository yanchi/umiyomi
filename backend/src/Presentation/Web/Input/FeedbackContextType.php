<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

enum FeedbackContextType
{
    case None;
    case Forecast;
    case RejectedInput;
}
