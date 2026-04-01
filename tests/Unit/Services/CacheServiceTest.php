<?php

namespace Tests\Unit\Services;

use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CacheService 단위 테스트
 *
 * 빈 배열 캐싱 금지 기능을 검증합니다.
 */
class CacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * 빈 배열은 캐시하지 않아야 합니다.
     */
    #[Test]
    public function it_does_not_cache_empty_arrays(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return [];
        };

        // 첫 번째 호출
        $result1 = CacheService::remember('test', 'empty_array', $callback);
        $this->assertEquals([], $result1);
        $this->assertEquals(1, $callCount);

        // 두 번째 호출 - 캐시되지 않았으므로 콜백 재실행
        $result2 = CacheService::remember('test', 'empty_array', $callback);
        $this->assertEquals([], $result2);
        $this->assertEquals(2, $callCount);

        // 캐시에 값이 없음을 확인
        $this->assertNull(Cache::get(CacheService::key('test', 'empty_array')));
    }

    /**
     * 비어있지 않은 배열은 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_empty_arrays(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return ['item1', 'item2'];
        };

        // 첫 번째 호출
        $result1 = CacheService::remember('test', 'non_empty_array', $callback);
        $this->assertEquals(['item1', 'item2'], $result1);
        $this->assertEquals(1, $callCount);

        // 두 번째 호출 - 캐시 히트
        $result2 = CacheService::remember('test', 'non_empty_array', $callback);
        $this->assertEquals(['item1', 'item2'], $result2);
        $this->assertEquals(1, $callCount); // 콜백 재실행 안함
    }

    /**
     * 문자열 등 배열이 아닌 값은 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_array_values(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 'string_value';
        };

        $result1 = CacheService::remember('test', 'string', $callback);
        $this->assertEquals('string_value', $result1);
        $this->assertEquals(1, $callCount);

        $result2 = CacheService::remember('test', 'string', $callback);
        $this->assertEquals('string_value', $result2);
        $this->assertEquals(1, $callCount);
    }

    /**
     * 정수 값은 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_integer_values(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 42;
        };

        $result1 = CacheService::remember('test', 'integer', $callback);
        $this->assertEquals(42, $result1);
        $this->assertEquals(1, $callCount);

        $result2 = CacheService::remember('test', 'integer', $callback);
        $this->assertEquals(42, $result2);
        $this->assertEquals(1, $callCount);
    }

    /**
     * 연관 배열도 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_associative_arrays(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return ['key' => 'value', 'count' => 10];
        };

        $result1 = CacheService::remember('test', 'assoc_array', $callback);
        $this->assertEquals(['key' => 'value', 'count' => 10], $result1);
        $this->assertEquals(1, $callCount);

        $result2 = CacheService::remember('test', 'assoc_array', $callback);
        $this->assertEquals(['key' => 'value', 'count' => 10], $result2);
        $this->assertEquals(1, $callCount);
    }

    /**
     * DB 테이블이 없으면 빈 배열을 반환하고 캐시하지 않아야 합니다.
     */
    #[Test]
    public function it_returns_empty_array_without_caching_when_table_not_exists(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return ['data'];
        };

        // 존재하지 않는 테이블 지정
        $result = CacheService::remember(
            'test',
            'no_table',
            $callback,
            null,
            'non_existent_table_xyz_123'
        );

        $this->assertEquals([], $result);
        $this->assertEquals(0, $callCount); // 콜백 실행 안함
        $this->assertNull(Cache::get(CacheService::key('test', 'no_table')));
    }

    /**
     * 캐시 키가 올바르게 생성되어야 합니다.
     */
    #[Test]
    public function it_generates_correct_cache_key(): void
    {
        $key = CacheService::key('modules', 'active_identifiers');
        $this->assertEquals('g7:modules:active_identifiers', $key);
    }

    /**
     * forget 메서드가 캐시를 삭제해야 합니다.
     */
    #[Test]
    public function it_forgets_cached_values(): void
    {
        // 값 캐시
        CacheService::remember('test', 'to_forget', fn () => ['data']);

        // 캐시 확인
        $this->assertNotNull(Cache::get(CacheService::key('test', 'to_forget')));

        // 캐시 삭제
        CacheService::forget('test', 'to_forget');

        // 삭제 확인
        $this->assertNull(Cache::get(CacheService::key('test', 'to_forget')));
    }

    /**
     * forgetMany 메서드가 여러 캐시를 삭제해야 합니다.
     */
    #[Test]
    public function it_forgets_many_cached_values(): void
    {
        // 여러 값 캐시
        CacheService::remember('test', 'key1', fn () => ['data1']);
        CacheService::remember('test', 'key2', fn () => ['data2']);

        // 캐시 확인
        $this->assertNotNull(Cache::get(CacheService::key('test', 'key1')));
        $this->assertNotNull(Cache::get(CacheService::key('test', 'key2')));

        // 여러 캐시 삭제
        CacheService::forgetMany('test', ['key1', 'key2']);

        // 삭제 확인
        $this->assertNull(Cache::get(CacheService::key('test', 'key1')));
        $this->assertNull(Cache::get(CacheService::key('test', 'key2')));
    }

    /**
     * refresh 메서드가 캐시를 갱신해야 합니다.
     */
    #[Test]
    public function it_refreshes_cached_values(): void
    {
        $value = 1;

        // 초기 캐시
        CacheService::remember('test', 'refresh', fn () => $value);
        $this->assertEquals(1, Cache::get(CacheService::key('test', 'refresh')));

        // 값 변경 후 refresh
        $value = 2;
        $result = CacheService::refresh('test', 'refresh', fn () => $value);

        $this->assertEquals(2, $result);
        $this->assertEquals(2, Cache::get(CacheService::key('test', 'refresh')));
    }
}
