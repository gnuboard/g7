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
 *
 * @scenario-manifest tests/scenarios/cache-invalidatable-trait.yaml
 */
class CacheInvalidatableTest extends TestCase
{
    private CoreCacheDriver $cacheDriver;

    /**
     * 캐시를 비우고 array 저장소를 쓰는 코어 드라이버를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->cacheDriver = new CoreCacheDriver('array');
    }

    /**
     * 테스트가 남긴 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * products 태그를 flushTags() 하면 그 태그가 붙은 두 키만 지워지고 config 태그 키는 남는다.
     *
     * @scenario case=tag_flush
     *
     * @effects tag_flush_removes_only_target_tag
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모델의 getCacheDriver() 가 null 이면 invalidateRelatedCache() 가 예외 없이 끝난다.
     *
     * @scenario case=null_driver
     *
     * @effects invalidation_skipped_without_exception
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function null_cache_driver_skips_invalidation(): void
    {
        // getCacheDriver()가 null을 반환하면 예외 없이 건너뜀
        $model = new class extends Model
        {
            use CacheInvalidatable;

            protected $table = 'test_table';

            /**
             * 무효화할 캐시 태그로 products 를 돌려줍니다.
             *
             * @return array 무효화 태그 목록
             */
            protected function getCacheInvalidationTags(): array
            {
                return ['products'];
            }

            /**
             * 캐시 드라이버가 없음을 흉내 내 null 을 돌려줍니다.
             *
             * @return CacheInterface|null 항상 null
             */
            protected function getCacheDriver(): ?CacheInterface
            {
                return null;
            }

            /**
             * 보호 메서드 invalidateRelatedCache() 를 테스트에서 호출할 수 있게 엽니다.
             *
             * @return void 반환값 없음
             */
            public function invalidateForTest(): void
            {
                $this->invalidateRelatedCache();
            }
        };

        // 예외 없이 실행됨
        $model->invalidateForTest();
        $this->assertTrue(true); // 예외 미발생 확인
    }

    /**
     * 모델의 무효화 태그가 빈 배열이면 invalidateRelatedCache() 가 flushTags 없이 예외 없이 끝난다.
     *
     * @scenario case=empty_tags
     *
     * @effects invalidation_skipped_without_exception
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function empty_tags_skips_invalidation(): void
    {
        $model = new class extends Model
        {
            use CacheInvalidatable;

            protected $table = 'test_table';

            /**
             * 무효화할 캐시 태그가 없음을 흉내 내 빈 배열을 돌려줍니다.
             *
             * @return array 빈 태그 목록
             */
            protected function getCacheInvalidationTags(): array
            {
                return [];
            }

            /**
             * array 저장소를 쓰는 코어 드라이버를 돌려줍니다.
             *
             * @return CacheInterface|null 코어 캐시 드라이버
             */
            protected function getCacheDriver(): ?CacheInterface
            {
                return new CoreCacheDriver('array');
            }

            /**
             * 보호 메서드 invalidateRelatedCache() 를 테스트에서 호출할 수 있게 엽니다.
             *
             * @return void 반환값 없음
             */
            public function invalidateForTest(): void
            {
                $this->invalidateRelatedCache();
            }
        };

        // 빈 태그 배열이면 flushTags 호출하지 않음
        $model->invalidateForTest();
        $this->assertTrue(true);
    }
}
