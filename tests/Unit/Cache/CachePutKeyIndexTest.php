<?php

namespace Tests\Unit\Cache;

use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Cache\ModuleCacheDriver;
use App\Extension\Cache\PluginCacheDriver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 확장 캐시의 put()·putMany() 키가 비우기(flush)로 지워지는지 검증합니다.
 *
 * flush() 는 키 색인에 적힌 키만 지운다. 수정 전에는 remember() 만 색인에 키를 적고 put()·putMany() 는
 * 적지 않아, 플러그인을 비활성·제거해도(PluginManager::flushPluginCache) put() 로 둔 키가 남았다
 * (실측: 재설치한 SSO 플러그인의 IdP 연속 실패 알림이 이전 IdP 의 억제 키에 막힘).
 * 모듈·플러그인 드라이버만 put() 키를 적고(코어는 flush() 호출처가 없음), 색인 항목의 만료 시각으로 쓸 때마다 정리한다.
 *
 * @scenario-manifest tests/scenarios/cache-put-key-index.yaml
 */
class CachePutKeyIndexTest extends TestCase
{
    /** 시험용 file 저장소 이름 */
    private const FILE_STORE = 'g7_put_index_file';

    /** 색인 쓰기만 실패하는 시험용 저장소 이름 */
    private const INDEX_FAILING_STORE = 'g7_put_index_failing';

    /** 시험용 플러그인 식별자 */
    private const PLUGIN = 'zz-put-index';

    /**
     * 시험용 저장소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.'.self::FILE_STORE => [
            'driver' => 'file',
            'path' => $this->fileStorePath(),
            'lock_path' => $this->fileStorePath(),
        ]]);
        config(['cache.stores.'.self::INDEX_FAILING_STORE => ['driver' => self::INDEX_FAILING_STORE]]);
        Cache::extend(self::INDEX_FAILING_STORE, fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 색인 키(`g7:_idx:`) 쓰기만 실패시키고 나머지는 그대로 저장합니다.
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $value  값
             * @param  int  $seconds  TTL (초)
             * @return bool 저장 성공 여부
             */
            public function put($key, $value, $seconds)
            {
                if (str_starts_with($key, 'g7:_idx:')) {
                    throw new \RuntimeException('index write denied (P2 put-index test)');
                }

                return parent::put($key, $value, $seconds);
            }
        }));
        Cache::store('array')->flush();
    }

    /**
     * 시험용 저장소와 시각 고정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->fileStorePath());
        Cache::store('array')->flush();

        parent::tearDown();
    }

    /**
     * 시험용 file 저장소 경로를 반환합니다.
     *
     * @return string 경로
     */
    private function fileStorePath(): string
    {
        return storage_path('framework/testing/cache-put-key-index');
    }

    /**
     * 드라이버의 키 색인을 64조각 전부 합쳐 읽습니다(옛 단일 색인은 빼고).
     *
     * @param  string  $prefix  드라이버 접두사
     * @param  string  $store  저장소 이름
     * @return array 키 색인
     */
    private function index(string $prefix, string $store = self::FILE_STORE): array
    {
        $index = [];
        for ($shard = 0; $shard < 64; $shard++) {
            $index += Cache::store($store)->get('g7:_idx:'.$prefix.':'.$shard, []);
        }

        return $index;
    }

    /**
     * 키가 든 색인 조각 번호를 구현과 같은 규칙(md5 앞 4자리 % 64)으로 계산합니다.
     *
     * @param  string  $resolvedKey  접두사 적용된 전체 키
     * @return int 조각 번호
     */
    private function shardOf(string $resolvedKey): int
    {
        return hexdec(substr(md5($resolvedKey), 0, 4)) % 64;
    }

    /**
     * 주어진 키와 같은 조각에 드는 다른 키 이름을 찾습니다.
     *
     * @param  string  $prefix  드라이버 접두사
     * @param  string  $key  기준 키 (접두사 없는 이름)
     * @return string 같은 조각에 드는 키 이름 (접두사 없음)
     */
    private function keyInSameShard(string $prefix, string $key): string
    {
        $target = $this->shardOf($prefix.':'.$key);
        for ($i = 0; ; $i++) {
            if ($this->shardOf($prefix.':same_shard_'.$i) === $target) {
                return 'same_shard_'.$i;
            }
        }
    }

    /**
     * 키를 담은 색인 조각 수를 셉니다.
     *
     * @param  string  $prefix  드라이버 접두사
     * @return int 비어 있지 않은 조각 수
     */
    private function usedShards(string $prefix): int
    {
        $used = 0;
        for ($shard = 0; $shard < 64; $shard++) {
            if (Cache::store(self::FILE_STORE)->get('g7:_idx:'.$prefix.':'.$shard, []) !== []) {
                $used++;
            }
        }

        return $used;
    }

    /**
     * 태그 미지원 저장소(file)에서 put() 로 둔 플러그인 키가 flush() 뒤 사라진다.
     *
     * @scenario case=file_put
     *
     * @effects put_key_removed_by_flush
     *
     * @return void 반환값 없음
     */
    public function test_put_key_is_removed_by_flush_on_file_store(): void
    {
        $driver = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);
        $driver->put('idp_failing_notified:1', true, 3600);
        $this->assertTrue($driver->has('idp_failing_notified:1'));

        $this->assertTrue($driver->flush());

        $this->assertFalse($driver->has('idp_failing_notified:1'));
    }

    /**
     * 태그 미지원 저장소(file)에서 putMany() 로 둔 키가 전부 flush() 뒤 사라진다.
     *
     * @scenario case=file_put_many
     *
     * @effects put_many_keys_removed_by_flush
     *
     * @return void 반환값 없음
     */
    public function test_put_many_keys_are_removed_by_flush_on_file_store(): void
    {
        $driver = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);
        $driver->putMany(['a' => 1, 'b' => 2], 3600);

        $driver->flush();

        $this->assertFalse($driver->has('a'));
        $this->assertFalse($driver->has('b'));
    }

    /**
     * 태그 지원 저장소(array)도 flush() 가 색인으로 지우므로 put() 키가 사라진다(모듈 드라이버).
     *
     * @scenario case=tag_store_put
     *
     * @effects put_key_removed_by_flush
     *
     * @return void 반환값 없음
     */
    public function test_module_put_key_is_removed_by_flush_on_tag_store(): void
    {
        $driver = new ModuleCacheDriver(self::PLUGIN, 'array');
        $this->assertTrue($driver->supportsTags());
        $driver->put('counter', 3, 3600);

        $driver->flush();

        $this->assertFalse($driver->has('counter'));
    }

    /**
     * 한 플러그인의 flush() 는 다른 플러그인이 put() 로 둔 키를 지우지 않는다.
     *
     * @scenario case=other_extension
     *
     * @effects other_extension_put_key_survives
     *
     * @return void 반환값 없음
     */
    public function test_flush_keeps_other_extension_put_keys(): void
    {
        $mine = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);
        $other = new PluginCacheDriver(self::PLUGIN.'-other', self::FILE_STORE);
        $mine->put('k', 'mine', 3600);
        $other->put('k', 'other', 3600);

        $mine->flush();

        $this->assertFalse($mine->has('k'));
        $this->assertSame('other', $other->get('k'));
    }

    /**
     * 코어 드라이버의 put()·putMany() 는 색인에 적지 않고, remember() 는 전과 같이 적는다.
     *
     * @scenario case=core_put
     *
     * @effects core_put_not_indexed
     *
     * @return void 반환값 없음
     */
    public function test_core_driver_does_not_index_put_keys(): void
    {
        $driver = new CoreCacheDriver(self::FILE_STORE);
        $driver->put('geoip:203.0.113.7', 'Asia/Seoul', 3600);
        $driver->putMany(['handoff:a' => 1], 3600);
        $driver->remember('settings', fn () => ['ok' => true], 3600);

        $index = $this->index('g7:core');

        $this->assertArrayNotHasKey('g7:core:geoip:203.0.113.7', $index);
        $this->assertArrayNotHasKey('g7:core:handoff:a', $index);
        $this->assertArrayHasKey('g7:core:settings', $index);
        $this->assertTrue($driver->has('geoip:203.0.113.7'));
    }

    /**
     * 색인 조각에 쓸 때 그 조각의 만료 시각이 지난 항목을 정리해, 키마다 고유한 put() 키가 색인에 쌓이지 않는다.
     * 정리는 쓰는 조각에서만 한다 — 쓰지 않는 조각은 커지지도 않는다.
     *
     * @scenario case=expired_entry
     *
     * @effects expired_entries_pruned_on_write
     *
     * @return void 반환값 없음
     */
    public function test_expired_index_entries_are_pruned_on_write(): void
    {
        $prefix = 'g7:plugin.'.self::PLUGIN;
        Carbon::setTestNow(Carbon::create(2026, 10, 25, 16, 0, 0));
        $driver = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);
        $driver->put('state:short', 'x', 60);
        $driver->remember('remembered:short', fn () => 'y', 60);
        $this->assertArrayHasKey($prefix.':state:short', $this->index($prefix));

        Carbon::setTestNow(Carbon::create(2026, 10, 25, 16, 2, 0));
        $later = $this->keyInSameShard($prefix, 'state:short');
        $driver->put($later, 'z', 3600);
        $driver->put($this->keyInSameShard($prefix, 'remembered:short'), 'w', 3600);

        $index = $this->index($prefix);
        $this->assertArrayNotHasKey($prefix.':state:short', $index);
        $this->assertArrayNotHasKey($prefix.':remembered:short', $index);
        $this->assertSame(
            ['tags' => [$prefix], 'expires' => Carbon::now()->getTimestamp() + 3600],
            $index[$prefix.':'.$later],
        );
    }

    /**
     * 옛 형식(태그 배열 그대로) 색인 항목을 읽어 flush() 와 flushTags() 가 그 키를 지운다.
     *
     * @scenario case=legacy_format
     *
     * @effects legacy_index_entries_flushed
     *
     * @return void 반환값 없음
     */
    public function test_legacy_index_entries_are_flushed(): void
    {
        $prefix = 'g7:plugin.'.self::PLUGIN;
        $store = Cache::store(self::FILE_STORE);
        $store->put($prefix.':legacy_all', 'a', 3600);
        $store->put($prefix.':legacy_group', 'b', 3600);
        $store->put('g7:_idx:'.$prefix, [
            $prefix.':legacy_all' => [$prefix],
            $prefix.':legacy_group' => [$prefix, 'group_a'],
        ], 3600);
        $driver = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);

        // 새 항목은 조각 색인에 적히고, 옛 단일 색인은 그대로 남는다(만료 시각이 없어 정리 대상도 아님)
        $driver->put('fresh', 'c', 3600);
        $this->assertArrayHasKey($prefix.':fresh', $this->index($prefix));
        $this->assertArrayHasKey($prefix.':legacy_all', $store->get('g7:_idx:'.$prefix, []));

        $this->assertTrue($driver->flushTags(['group_a']));
        $this->assertFalse($driver->has('legacy_group'));
        $this->assertTrue($driver->has('legacy_all'));

        $this->assertTrue($driver->flush());
        $this->assertFalse($driver->has('legacy_all'));
        $this->assertFalse($driver->has('fresh'));
    }

    /**
     * 색인은 키 해시로 조각에 나뉘어 put 한 번이 한 조각만 고치고, 옛 단일 색인에는 새로 적지 않으며,
     * flush() 는 모든 조각의 키를 지운다.
     *
     * @scenario case=sharded
     *
     * @effects index_sharded_by_key
     *
     * @return void 반환값 없음
     */
    public function test_index_is_sharded_and_flush_covers_every_shard(): void
    {
        $prefix = 'g7:plugin.'.self::PLUGIN;
        $driver = new PluginCacheDriver(self::PLUGIN, self::FILE_STORE);
        $values = [];
        for ($i = 0; $i < 200; $i++) {
            $values["post_view_free_{$i}_u"] = true;
        }
        $driver->putMany($values, 3600);

        $this->assertCount(200, $this->index($prefix));
        // 200키가 64조각에 고르게 퍼진다(crc32 로는 숫자만 다른 키가 32조각에 몰렸다)
        $this->assertGreaterThan(56, $this->usedShards($prefix));
        $this->assertNull(Cache::store(self::FILE_STORE)->get('g7:_idx:'.$prefix));

        $this->assertTrue($driver->flush());

        $this->assertSame(0, $this->usedShards($prefix));
        $this->assertFalse($driver->has('post_view_free_0_u'));
        $this->assertFalse($driver->has('post_view_free_199_u'));
    }

    /**
     * 색인 쓰기가 실패해도 put() 는 값을 저장한 결과(true)를 돌려주고 경고만 남긴다.
     *
     * @scenario case=index_write_failure
     *
     * @effects index_write_failure_keeps_put_result
     *
     * @return void 반환값 없음
     */
    public function test_index_write_failure_keeps_put_result(): void
    {
        Log::spy();
        $driver = new PluginCacheDriver(self::PLUGIN, self::INDEX_FAILING_STORE);

        $this->assertTrue($driver->put('k', 'v', 3600));
        $this->assertSame('v', $driver->get('k'));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => ($context['operation'] ?? null) === 'index'
        )->once();
    }
}
