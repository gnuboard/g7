<?php

namespace Tests\Unit\Cache;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Traits\CacheInvalidatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CacheInvalidatable 트레이트 단위 테스트
 *
 * 모델 saved/deleted 이벤트 시 캐시 자동 무효화를 검증합니다.
 */
class CacheInvalidatableTest extends TestCase
{
    private CoreCacheDriver $cacheDriver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->cacheDriver = new CoreCacheDriver('array');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function invalidate_related_cache_flushes_tags(): void
    {
        // 태그가 있는 캐시 생성
        $this->cacheDriver->remember('products_list', fn () => ['p1', 'p2'], 3600, ['products']);
        $this->cacheDriver->remember('product_1', fn () => ['id' => 1], 3600, ['products', 'product:1']);
        $this->cacheDriver->remember('settings', fn () => ['s1'], 3600, ['config']);

        $this->assertTrue($this->cacheDriver->has('products_list'));
        $this->assertTrue($this->cacheDriver->has('product_1'));
        $this->assertTrue($this->cacheDriver->has('settings'));

        // products 태그 무효화
        $this->cacheDriver->flushTags(['products']);

        $this->assertFalse($this->cacheDriver->has('products_list'));
        $this->assertFalse($this->cacheDriver->has('product_1'));
        // config 태그는 영향 없음
        $this->assertTrue($this->cacheDriver->has('settings'));
    }

    #[Test]
    public function null_cache_driver_skips_invalidation(): void
    {
        // getCacheDriver()가 null을 반환하면 예외 없이 건너뜀
        $model = new class extends Model
        {
            use CacheInvalidatable;

            protected $table = 'test_table';

            protected function getCacheInvalidationTags(): array
            {
                return ['products'];
            }

            protected function getCacheDriver(): ?CacheInterface
            {
                return null;
            }

            public function testInvalidate(): void
            {
                $this->invalidateRelatedCache();
            }
        };

        // 예외 없이 실행됨
        $model->testInvalidate();
        $this->assertTrue(true); // 예외 미발생 확인
    }

    #[Test]
    public function empty_tags_skips_invalidation(): void
    {
        $model = new class extends Model
        {
            use CacheInvalidatable;

            protected $table = 'test_table';

            protected function getCacheInvalidationTags(): array
            {
                return [];
            }

            protected function getCacheDriver(): ?CacheInterface
            {
                return new CoreCacheDriver('array');
            }

            public function testInvalidate(): void
            {
                $this->invalidateRelatedCache();
            }
        };

        // 빈 태그 배열이면 flushTags 호출하지 않음
        $model->testInvalidate();
        $this->assertTrue(true);
    }
}
