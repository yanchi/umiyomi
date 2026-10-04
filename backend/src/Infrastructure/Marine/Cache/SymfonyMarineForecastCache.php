<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\Cache;

use App\Application\Marine\Port\MarineForecastCache;
use App\Domain\Marine\Coordinate;
use App\Domain\Marine\MarineForecast;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * get() のコールバック方式はヒット/ミスしか表せず「再利用はできないが代替表示には使える」を扱えないため、getItem() / save() を使う.
 */
final readonly class SymfonyMarineForecastCache implements MarineForecastCache
{
    // 保存形式を変えたときに古いデータを読まないよう、キーに版を含める
    private const string KEY_PREFIX = 'marine_forecast.v1.';

    public function __construct(
        #[Target('cache.marine_forecast')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(Coordinate $coordinate): ?MarineForecast
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$coordinate->key());
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return $value instanceof MarineForecast ? $value : null;
    }

    public function save(MarineForecast $forecast): void
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$forecast->coordinate->key());
        $item->set($forecast);
        // 代替表示に使える期間を過ぎたら残しておく意味がないので、期限を Domain のポリシーに合わせる。
        // 保存は取得の直後なので、保存時点からの期間で足りる（取得日時を基準にすると、時刻を固定したテストで即座に期限切れになる）
        $item->expiresAfter(new \DateInterval(MarineForecast::FALLBACK_PERIOD));
        $this->pool->save($item);
    }
}
