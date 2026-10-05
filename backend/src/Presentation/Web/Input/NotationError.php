<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 読み取りの失敗の原因。文言は持たない（欄の名前を埋め込むため CoordinateQueryParser が変換する）.
 */
enum NotationError
{
    case Empty;
    case TooLong;
    case Unreadable;
    case Url;
    case ConflictingDirections;
    case SignAndDirection;
    case DegreeNotInteger;
    case MinuteSecondRange;
    case AxisMismatch;
    case OutOfRange;
    case TooManyValues;
    case UnitlessDegreeMinutes;
    case DecimalComma;
    case PairDirectionMixed;
    case PairSameAxis;
    case SwappedOrder;
    case BothPairs;
}
