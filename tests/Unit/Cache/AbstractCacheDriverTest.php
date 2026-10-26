<?php

namespace Tests\Unit\Cache;

use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Cache\ModuleCacheDriver;
use App\Extension\Cache\PluginCacheDriver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AbstractCacheDriver (CoreCacheDriver 기반) 단위 테스트
 *
 * 공통 캐시 연산(CRUD, remember, flush, 태그, withStore)을 검증합니다.
 *
 * @scenario-manifest tests/scenarios/cache-driver-common-operations.yaml
 */
class AbstractCacheDriverTest extends TestCase
{
    private CoreCacheDriver $driver;

    /**
     * 캐시를 비우고 array 저장소를 쓰는 코어 드라이버를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->driver = new CoreCacheDriver('array');
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

    // === 키 접두사 ===

    /**
     * 코어 드라이버는 키 앞에 `g7:core:` 접두사를 붙인다.
     *
     * @scenario case=key_prefix
     *
     * @effects key_prefixed_by_scope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function core_driver_resolves_key_with_correct_prefix(): void
    {
        $this->assertEquals('g7:core:test_key', $this->driver->resolveKey('test_key'));
    }

    /**
     * 모듈 드라이버는 키 앞에 `g7:module.{식별자}:` 접두사를 붙인다.
     *
     * @scenario case=key_prefix
     *
     * @effects key_prefixed_by_scope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function module_driver_resolves_key_with_module_prefix(): void
    {
        $driver = new ModuleCacheDriver('sirsoft-ecommerce', 'array');
        $this->assertEquals(
            'g7:module.sirsoft-ecommerce:products',
            $driver->resolveKey('products')
        );
    }

    /**
     * 플러그인 드라이버는 키 앞에 `g7:plugin.{식별자}:` 접두사를 붙인다.
     *
     * @scenario case=key_prefix
     *
     * @effects key_prefixed_by_scope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function plugin_driver_resolves_key_with_plugin_prefix(): void
    {
        $driver = new PluginCacheDriver('sirsoft-payment', 'array');
        $this->assertEquals(
            'g7:plugin.sirsoft-payment:gateways',
            $driver->resolveKey('gateways')
        );
    }

    // === 기본 CRUD ===

    /**
     * put() 으로 둔 값을 get() 이 그대로 돌려준다.
     *
     * @scenario case=crud
     *
     * @effects value_round_trips
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function put_and_get_stores_and_retrieves_value(): void
    {
        $this->driver->put('test_key', 'test_value', 3600);

        $this->assertEquals('test_value', $this->driver->get('test_key'));
    }

    /**
     * 없는 키를 get() 하면 null 을, 기본값을 넘기면 그 기본값을 돌려준다.
     *
     * @scenario case=crud
     *
     * @effects value_round_trips
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_returns_default_for_missing_key(): void
    {
        $this->assertNull($this->driver->get('nonexistent'));
        $this->assertEquals('fallback', $this->driver->get('nonexistent', 'fallback'));
    }

    /**
     * has() 는 둔 키에만 true 이고 없는 키에는 false 다.
     *
     * @scenario case=crud
     *
     * @effects presence_tracked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function has_returns_true_for_existing_key(): void
    {
        $this->driver->put('exists', 'value');

        $this->assertTrue($this->driver->has('exists'));
        $this->assertFalse($this->driver->has('not_exists'));
    }

    /**
     * forget() 으로 지운 키는 has() 가 false 가 된다.
     *
     * @scenario case=crud
     *
     * @effects presence_tracked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function forget_removes_key(): void
    {
        $this->driver->put('to_delete', 'value');
        $this->assertTrue($this->driver->has('to_delete'));

        $this->driver->forget('to_delete');
        $this->assertFalse($this->driver->has('to_delete'));
    }

    // === Remember 패턴 ===

    /**
     * remember() 는 첫 호출에만 콜백을 실행하고 두 번째 호출은 저장된 값을 돌려준다.
     *
     * @scenario case=remember
     *
     * @effects callback_runs_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remember_caches_callback_result(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 'computed_value';
        };

        // 첫 번째 호출: 콜백 실행
        $result1 = $this->driver->remember('key', $callback, 3600);
        $this->assertEquals('computed_value', $result1);
        $this->assertEquals(1, $callCount);

        // 두 번째 호출: 캐시 히트 (콜백 미실행)
        $result2 = $this->driver->remember('key', $callback, 3600);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callCount);
    }

    /**
     * rememberQuery() 결과가 `query:{해시}` 키로 저장된다.
     *
     * @scenario case=remember
     *
     * @effects query_prefix_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remember_query_uses_query_prefix(): void
    {
        $result = $this->driver->rememberQuery('abc123', fn () => ['data'], 3600);

        $this->assertEquals(['data'], $result);
        $this->assertTrue($this->driver->has('query:abc123'));
    }

    // === 벌크 연산 ===

    /**
     * putMany() 로 둔 세 값을 many() 가 모두 돌려준다.
     *
     * @scenario case=bulk
     *
     * @effects bulk_round_trips
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function put_many_and_many_bulk_operations(): void
    {
        $this->driver->putMany([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], 3600);

        $results = $this->driver->many(['key1', 'key2', 'key3']);
        $this->assertEquals('value1', $results['key1']);
        $this->assertEquals('value2', $results['key2']);
        $this->assertEquals('value3', $results['key3']);
    }

    // === Refresh ===

    /**
     * refresh() 는 기존 값을 콜백 결과로 바꾸고 그 값을 돌려준다.
     *
     * @scenario case=refresh
     *
     * @effects refresh_overwrites
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function refresh_replaces_cached_value(): void
    {
        $this->driver->put('refresh_key', 'old_value');

        $result = $this->driver->refresh('refresh_key', fn () => 'new_value', 3600);

        $this->assertEquals('new_value', $result);
        $this->assertEquals('new_value', $this->driver->get('refresh_key'));
    }

    // === withStore 불변 복제 ===

    /**
     * withStore() 는 원본의 저장소(array)를 그대로 두고 다른 저장소(file)를 쓰는 새 인스턴스를 돌려준다.
     *
     * @scenario case=with_store
     *
     * @effects with_store_immutable_clone
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function with_store_creates_new_instance(): void
    {
        $original = new CoreCacheDriver('array');
        $clone = $original->withStore('file');

        $this->assertEquals('array', $original->getStore());
        $this->assertEquals('file', $clone->getStore());
        $this->assertNotSame($original, $clone);
    }

    // === Flush (태그 미지원 드라이버 — array 기반 인덱스 전략) ===

    /**
     * 태그 미지원 저장소(array)에서 flush() 가 remember() 로 색인된 드라이버 키를 모두 지운다.
     *
     * @scenario case=flush
     *
     * @effects flush_removes_indexed_keys
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function flush_removes_all_driver_keys_via_index(): void
    {
        // array 드라이버는 태그를 지원하지 않으므로 인덱스 기반 flush
        $this->driver->remember('key1', fn () => 'v1', 3600);
        $this->driver->remember('key2', fn () => 'v2', 3600);

        $this->assertTrue($this->driver->has('key1'));
        $this->assertTrue($this->driver->has('key2'));

        $this->driver->flush();

        $this->assertFalse($this->driver->has('key1'));
        $this->assertFalse($this->driver->has('key2'));
    }

    /**
     * flushTags() 는 지정한 태그(group_a) 키만 지우고 다른 태그(group_b) 키는 남긴다.
     *
     * @scenario case=flush
     *
     * @effects flush_tags_scoped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function flush_tags_removes_only_tagged_keys(): void
    {
        $this->driver->remember('tagged', fn () => 'v1', 3600, ['group_a']);
        $this->driver->remember('other', fn () => 'v2', 3600, ['group_b']);

        $this->driver->flushTags(['group_a']);

        $this->assertFalse($this->driver->has('tagged'));
        // group_b 키는 유지
        $this->assertTrue($this->driver->has('other'));
    }

    // === 드라이버 격리 ===

    /**
     * 코어 드라이버와 모듈 드라이버에 같은 이름으로 둔 키가 서로 덮어쓰지 않는다.
     *
     * @scenario case=isolation
     *
     * @effects drivers_isolated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function different_drivers_have_isolated_keys(): void
    {
        $coreDriver = new CoreCacheDriver('array');
        $moduleDriver = new ModuleCacheDriver('sirsoft-ecommerce', 'array');

        $coreDriver->put('shared_name', 'core_value');
        $moduleDriver->put('shared_name', 'module_value');

        $this->assertEquals('core_value', $coreDriver->get('shared_name'));
        $this->assertEquals('module_value', $moduleDriver->get('shared_name'));
    }

    /**
     * 모듈 드라이버의 flush() 는 모듈 키만 지우고 코어 키는 남긴다.
     *
     * @scenario case=isolation
     *
     * @effects drivers_isolated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function module_flush_does_not_affect_core(): void
    {
        $coreDriver = new CoreCacheDriver('array');
        $moduleDriver = new ModuleCacheDriver('sirsoft-ecommerce', 'array');

        $coreDriver->remember('core_key', fn () => 'core_val', 3600);
        $moduleDriver->remember('mod_key', fn () => 'mod_val', 3600);

        $moduleDriver->flush();

        $this->assertFalse($moduleDriver->has('mod_key'));
        $this->assertTrue($coreDriver->has('core_key'));
    }

    // === 메타 ===

    /**
     * getStore() 가 생성할 때 지정한 저장소 이름(array)을 돌려준다.
     *
     * @scenario case=meta
     *
     * @effects store_meta_exposed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_store_returns_current_store_name(): void
    {
        $this->assertEquals('array', $this->driver->getStore());
    }

    /**
     * supportsTags() 가 bool 값을 돌려준다.
     *
     * @scenario case=meta
     *
     * @effects store_meta_exposed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function supports_tags_returns_boolean(): void
    {
        // array 드라이버는 태그를 지원하지 않음
        $this->assertIsBool($this->driver->supportsTags());
    }
}
