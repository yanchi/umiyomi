<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * 提供元に問い合わせる期間。正時でない・逆転した期間を Provider に渡さないための VO.
 */
final readonly class ForecastPeriod
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $utc = new \DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);

        if (!self::isOnTheHour($from) || !self::isOnTheHour($to)) {
            throw new \InvalidArgumentException('Forecast period must start and end on the hour.');
        }
        if ($from >= $to) {
            throw new \InvalidArgumentException('Forecast period must start before it ends.');
        }

        $this->from = $from;
        $this->to = $to;
    }

    public static function startingAt(\DateTimeImmutable $now, int $hours): self
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $from = $utc->setTime((int) $utc->format('G'), 0);

        return new self($from, $from->modify(\sprintf('%+d hours', $hours)));
    }

    private static function isOnTheHour(\DateTimeImmutable $time): bool
    {
        return '00:00.000000' === $time->format('i:s.u');
    }
}
