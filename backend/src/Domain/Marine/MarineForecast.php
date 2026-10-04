<?php

declare(strict_types=1);

namespace App\Domain\Marine;

/**
 * ある地点について、ある時点に取得した予報のまとまり.
 */
final readonly class MarineForecast
{
    /** この期間内は取得し直さない（FR-014） */
    public const string REUSE_PERIOD = 'PT1H';

    /** 取得に失敗したとき、この期間内の予報なら代替表示に使える（FR-013） */
    public const string FALLBACK_PERIOD = 'PT24H';

    public \DateTimeImmutable $fetchedAt;

    /**
     * @param list<HourlyForecast> $hours 時刻の昇順、重複なし
     */
    public function __construct(
        public Coordinate $coordinate,
        \DateTimeImmutable $fetchedAt,
        public Availability $wind,
        public Availability $sea,
        public array $hours,
    ) {
        // 両方失敗は「予報」ではなく取得失敗。Provider が FetchFailure を投げる
        if (Availability::FetchFailed === $wind && Availability::FetchFailed === $sea) {
            throw new \InvalidArgumentException('A forecast must contain at least one fetched group.');
        }

        $previous = null;
        foreach ($hours as $hour) {
            if (null !== $previous && $hour->time <= $previous) {
                throw new \InvalidArgumentException('Hourly forecasts must be in strictly ascending order.');
            }
            if (Availability::Available !== $wind && $hour->hasWindValue()) {
                throw new \InvalidArgumentException('Unavailable wind group must not contain values.');
            }
            if (Availability::Available !== $sea && $hour->hasSeaValue()) {
                throw new \InvalidArgumentException('Unavailable sea group must not contain values.');
            }
            $previous = $hour->time;
        }

        $this->fetchedAt = $fetchedAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function isReusableAt(\DateTimeImmutable $now): bool
    {
        return $now < $this->nextRefetchAt();
    }

    public function isFallbackUsableAt(\DateTimeImmutable $now): bool
    {
        return $now < $this->fetchedAt->add(new \DateInterval(self::FALLBACK_PERIOD));
    }

    public function nextRefetchAt(): \DateTimeImmutable
    {
        return $this->fetchedAt->add(new \DateInterval(self::REUSE_PERIOD));
    }

    /**
     * 部分的な予報を 1 時間再利用すると失敗したグループがその間ずっと欠けたままになるため、完全な予報だけを保存する.
     */
    public function isComplete(): bool
    {
        return Availability::FetchFailed !== $this->wind && Availability::FetchFailed !== $this->sea;
    }
}
