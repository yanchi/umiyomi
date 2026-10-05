<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Marine\Port\Clock;
use App\Application\Marine\Port\MarineForecastProvider;
use App\Domain\Marine\Availability;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\FetchFailure;
use App\Domain\Marine\ForecastPeriod;
use App\Domain\Marine\MarineForecast;

final class FakeMarineForecastProvider implements MarineForecastProvider
{
    private bool $fails = false;
    private bool $crashes = false;
    private Availability $sea = Availability::Available;
    private int $callCount = 0;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function forecast(Coordinate $coordinate, ForecastPeriod $period): MarineForecast
    {
        ++$this->callCount;

        if ($this->crashes) {
            throw new \RuntimeException('Fake provider is set to crash.');
        }

        if ($this->fails) {
            throw new FetchFailure('Fake provider is set to fail.');
        }

        $hours = (int) (($period->to->getTimestamp() - $period->from->getTimestamp()) / 3600);

        return MarineForecastBuilder::create()
            ->at($coordinate)
            ->fetchedAt($this->clock->now())
            ->hoursFrom($period->from, $hours + 1)
            ->sea($this->sea)
            ->build();
    }

    public function willFail(bool $fails = true): void
    {
        $this->fails = $fails;
    }

    // FetchFailure ではない想定外の例外（500 の画面を再現するため）
    public function willCrash(): void
    {
        $this->crashes = true;
    }

    public function willReturnSeaAvailability(Availability $sea): void
    {
        $this->sea = $sea;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
