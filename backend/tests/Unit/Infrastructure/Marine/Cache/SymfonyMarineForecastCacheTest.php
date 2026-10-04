<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Marine\Cache;

use App\Domain\Marine\Coordinate;
use App\Infrastructure\Marine\Cache\SymfonyMarineForecastCache;
use App\Tests\Support\MarineForecastBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SymfonyMarineForecastCacheTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $pool;
    private SymfonyMarineForecastCache $cache;

    protected function setUp(): void
    {
        // CacheItem::expiresAfter() は実時間から期限を計算するため、時計は現在時刻から始める
        $this->clock = new MockClock();
        $this->pool = new ArrayAdapter(clock: $this->clock);
        $this->cache = new SymfonyMarineForecastCache($this->pool);
    }

    public function testFindsSavedForecast(): void
    {
        $forecast = MarineForecastBuilder::create()->build();

        $this->cache->save($forecast);

        self::assertEquals($forecast, $this->cache->find(new Coordinate(27.75, 129.05)));
    }

    public function testReturnsNullWhenNotSaved(): void
    {
        self::assertNull($this->cache->find(new Coordinate(27.75, 129.05)));
    }

    public function testUsesVersionedCoordinateKey(): void
    {
        $this->cache->save(MarineForecastBuilder::create()->build());
        $this->cache->save(MarineForecastBuilder::create()->at(new Coordinate(-0.5, -120.0))->build());

        self::assertTrue($this->pool->hasItem('marine_forecast.v1.27.75_129.05'));
        self::assertTrue($this->pool->hasItem('marine_forecast.v1.-0.50_-120.00'));
    }

    public function testRoundedCoordinatesShareTheSameForecast(): void
    {
        $this->cache->save(MarineForecastBuilder::create()->at(new Coordinate(27.7500, 129.05))->build());

        self::assertNotNull($this->cache->find(new Coordinate(27.75, 129.0500)));
        self::assertNotNull($this->cache->find(new Coordinate(27.7549, 129.0499)));
        self::assertNull($this->cache->find(new Coordinate(27.76, 129.05)));
    }

    public function testReturnsNullForUnexpectedValue(): void
    {
        $item = $this->pool->getItem('marine_forecast.v1.27.75_129.05');
        $this->pool->save($item->set('broken'));

        self::assertNull($this->cache->find(new Coordinate(27.75, 129.05)));
    }

    public function testKeepsForecastFor24HoursAfterSaving(): void
    {
        $this->cache->save(MarineForecastBuilder::create()->build());

        $this->clock->modify('+23 hours 59 minutes');
        self::assertNotNull($this->cache->find(new Coordinate(27.75, 129.05)));

        $this->clock->modify('+1 minute 1 second');
        self::assertNull($this->cache->find(new Coordinate(27.75, 129.05)));
    }
}
