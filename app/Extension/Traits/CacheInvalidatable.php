<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Log;

/**
 * 캐시 자동 무효화 트레이트
 *
 * 모델에 적용하면 saved/deleted 이벤트 시 관련 태그 캐시를 자동 무효화합니다.
 *
 * 사용 예:
 * ```php
 * class Product extends Model
 * {
 *     use CacheInvalidatable;
 *
 *     protected function getCacheInvalidationTags(): array
 *     {
 *         return ['products', 'product:' . $this->id];
 *     }
 *
 *     protected function getCacheDriver(): ?CacheInterface
 *     {
 *         return app(ModuleManager::class)
 *             ->getModule('sirsoft-ecommerce')?->getCache();
 *     }
 * }
 * ```
 *
 * @since engine-v1.18.0
 */
trait CacheInvalidatable
{
    /**
     * 트레이트 부트 메서드
     *
     * saved/deleted 이벤트에 캐시 무효화 로직을 등록합니다.
     *
     * @return void
     */
    public static function bootCacheInvalidatable(): void
    {
        static::saved(fn ($model) => $model->invalidateRelatedCache());
        static::deleted(fn ($model) => $model->invalidateRelatedCache());
    }

    /**
     * 무효화할 캐시 태그를 반환합니다.
     *
     * 모델에서 구현해야 합니다.
     *
     * @return array 태그 배열
     */
    abstract protected function getCacheInvalidationTags(): array;

    /**
     * 사용할 CacheInterface를 반환합니다.
     *
     * 모델에서 구현해야 합니다. null을 반환하면 무효화를 건너뜁니다.
     *
     * @return CacheInterface|null 캐시 드라이버 인스턴스
     */
    abstract protected function getCacheDriver(): ?CacheInterface;

    /**
     * 관련 캐시를 무효화합니다.
     *
     * @return void
     */
    protected function invalidateRelatedCache(): void
    {
        $cache = $this->getCacheDriver();
        if ($cache === null) {
            return;
        }

        $tags = $this->getCacheInvalidationTags();
        if (empty($tags)) {
            return;
        }

        try {
            $cache->flushTags($tags);
        } catch (\Exception $e) {
            Log::warning('캐시 자동 무효화 실패', [
                'model' => static::class,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
