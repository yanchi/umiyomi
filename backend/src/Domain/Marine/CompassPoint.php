<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 日本語の表示名は Presentation の責務なので、ここでは方位の識別子だけを持つ.
 */
enum CompassPoint: string
{
    case N = 'N';
    case NNE = 'NNE';
    case NE = 'NE';
    case ENE = 'ENE';
    case E = 'E';
    case ESE = 'ESE';
    case SE = 'SE';
    case SSE = 'SSE';
    case S = 'S';
    case SSW = 'SSW';
    case SW = 'SW';
    case WSW = 'WSW';
    case W = 'W';
    case WNW = 'WNW';
    case NW = 'NW';
    case NNW = 'NNW';

    private const float SECTOR = 22.5;

    /**
     * 「来る方向」の角度（0° = 北）を変換する。各方位は中心 ±11.25° で、境界値は時計回り側に含める.
     */
    public static function fromDegrees(float $degrees): self
    {
        $normalized = fmod($degrees, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        $index = (int) floor(($normalized + self::SECTOR / 2) / self::SECTOR) % 16;

        return self::cases()[$index];
    }
}
