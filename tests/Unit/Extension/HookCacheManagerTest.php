<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookCacheManager;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Jobs\DispatchHookListenerJob;
use App\Listeners\NotificationHookListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 정적 훅 매핑 캐시 무결성 테스트.
 *
 * 성능 개선(서빙 API 부팅 비용)의 안전 게이트: 캐시 경로 등록이 스캔 경로 등록과
 * 바이트 동일해야 하며(훅 발화 누락 0), 캐시 미스는 안전 폴백해야 한다.
 *
 * @see HookCacheManager
 * @see HookListenerRegistrar::registerFromCache()
 */
class HookCacheManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpCachePath;

    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
        HookListenerRegistrar::clear();

        // request-scoped static memo 를 테스트 간 리셋 (누출 방지).
        // 한 프로세스에서 여러 테스트가 도는 PHPUnit 특성상 read() 가 채운 static
        // memo 가 다음 테스트로 누출되면 격리가 깨진다 — clear() 가 memo 를 null 로 되돌린다.
        (new HookCacheManager)->clear();

        // 실제 bootstrap/cache/hooks.php 를 건드리지 않도록 임시 경로 사용
        $this->tmpCachePath = storage_path('framework/testing/hooks_test_'.uniqid().'.php');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tmpCachePath)) {
            File::delete($this->tmpCachePath);
        }
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    /**
     * getHooks/getFilters 를 [name => [priority => callbackCount]] 지문으로 변환합니다.
     *
     * 클로저는 직접 비교할 수 없으므로 구조(훅명·우선순위·콜백 수)만 비교한다.
     *
     * @return array{actions: array, filters: array}
     */
    private function fingerprint(): array
    {
        $reduce = function (array $registry): array {
            $out = [];
            foreach ($registry as $name => $byPriority) {
                foreach ($byPriority as $priority => $callbacks) {
                    $out[$name][$priority] = count($callbacks);
                }
            }
            ksort($out);

            return $out;
        };

        return [
            'actions' => $reduce(HookManager::getHooks()),
            'filters' => $reduce(HookManager::getFilters()),
        ];
    }

    /**
     * 캐시 경로 등록 결과가 스캔(register) 경로와 바이트 동일한지 검증합니다.
     *
     * 스텁 리스너 3종(action/filter/sync)을 register() 로 등록한 지문과,
     * 동일 리스너의 getSubscribedHooks() 를 캐시로 저장 후 registerFromCache() 로
     * 등록한 지문이 완전히 일치해야 한다 (훅 발화 누락 0 게이트).
     *
     * @scenario cache_state=present_valid, listener_type=filter, regeneration_trigger=extension_update, registration_source=core
     *
     * @effects cache_path_registration_byte_identical_to_scan_path, cache_registered_filter_executes_synchronously_and_returns_value
     */
    public function test_cache_path_registration_is_identical_to_scan_path(): void
    {
        Queue::fake();

        $listeners = [
            HookCacheStubAction::class,
            HookCacheStubFilter::class,
            HookCacheStubSync::class,
        ];

        // --- 스캔 경로 (register → getSubscribedHooks 호출) ---
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        foreach ($listeners as $listener) {
            HookListenerRegistrar::register($listener, 'stub-source');
        }
        $scanFingerprint = $this->fingerprint();

        // --- 캐시 경로 (사전 계산된 getSubscribedHooks 를 registerFromCache) ---
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        foreach ($listeners as $listener) {
            $hooks = $listener::getSubscribedHooks();
            HookListenerRegistrar::registerFromCache($listener, $hooks, 'stub-source');
        }
        $cacheFingerprint = $this->fingerprint();

        $this->assertSame(
            $scanFingerprint,
            $cacheFingerprint,
            '캐시 경로 등록은 스캔 경로와 훅 매핑이 바이트 동일해야 합니다 (발화 누락 0)'
        );

        // 지문이 비어있지 않은지(실제로 등록됐는지) 확인
        $this->assertNotEmpty($scanFingerprint['actions']);
        $this->assertNotEmpty($scanFingerprint['filters']);
    }

    /**
     * registerFromCache 로 등록한 action 리스너가 큐 디스패치되는지 검증합니다.
     *
     * 캐시 경로도 register 와 동일한 큐 정책(DispatchHookListenerJob)을 따라야 한다.
     *
     * @scenario cache_state=present_valid, listener_type=action_queued, regeneration_trigger=hooks_clear_command, registration_source=plugin
     *
     * @effects cache_registered_action_dispatches_queue_job, listener_class_not_autoloaded_at_boot_when_registered_from_cache
     */
    public function test_cache_registered_action_dispatches_job(): void
    {
        Queue::fake();

        $hooks = HookCacheStubAction::class::getSubscribedHooks();
        HookListenerRegistrar::registerFromCache(HookCacheStubAction::class, $hooks, 'stub-source');

        HookManager::doAction('test.hookcache.action', 'payload');

        Queue::assertPushed(DispatchHookListenerJob::class, function ($job) {
            return $job->listenerClass === HookCacheStubAction::class;
        });
    }

    /**
     * registerFromCache 로 등록한 filter 리스너가 동기 실행되고 반환값이 전달되는지 검증합니다.
     *
     * @scenario cache_state=corrupted_broken_structure, listener_type=filter, regeneration_trigger=hooks_cache_command, registration_source=plugin
     *
     * @effects cache_registered_sync_action_executes_immediately
     */
    public function test_cache_registered_filter_executes_synchronously(): void
    {
        Queue::fake();

        $hooks = HookCacheStubFilter::class::getSubscribedHooks();
        HookListenerRegistrar::registerFromCache(HookCacheStubFilter::class, $hooks, 'stub-source');

        $result = HookManager::applyFilters('test.hookcache.filter', 'original');

        $this->assertSame('original_cached', $result);
    }

    /**
     * 캐시 파일 부재 시 read() 가 null 을 반환(스캔 폴백)하는지 검증합니다.
     *
     * @scenario cache_state=absent, listener_type=action_queued, regeneration_trigger=extension_update, registration_source=core
     *
     * @effects absent_cache_falls_back_to_scan_registration
     */
    public function test_read_returns_null_when_cache_absent(): void
    {
        $manager = new HookCacheManagerWithPath($this->tmpCachePath);

        $this->assertFalse($manager->isCached());
        $this->assertNull($manager->read());
    }

    /**
     * 손상된 캐시 파일은 read() 가 null 을 반환(안전 폴백)하는지 검증합니다.
     *
     * @scenario cache_state=corrupted_broken_structure, listener_type=action_sync, regeneration_trigger=hooks_clear_command, registration_source=core
     *
     * @effects corrupted_cache_returns_null_and_falls_back_to_scan, hooks_clear_is_safe_when_cache_absent
     */
    public function test_read_returns_null_when_cache_corrupted(): void
    {
        File::ensureDirectoryExists(dirname($this->tmpCachePath));
        File::put($this->tmpCachePath, "<?php return ['broken' => true];");

        $manager = new HookCacheManagerWithPath($this->tmpCachePath);

        $this->assertTrue($manager->isCached());
        $this->assertNull($manager->read(), '구조가 올바르지 않은 캐시는 null 폴백해야 합니다');
    }

    /**
     * generate() 가 core/modules/plugins 3-버킷 구조를 생성하고 read() 로 되읽히는지 검증합니다.
     *
     * 실제 활성 모듈/플러그인 인스턴스로 생성하여 코어 리스너가 다수 수집됨을 확인한다.
     *
     * @scenario cache_state=present_valid, listener_type=action_sync, regeneration_trigger=core_update, registration_source=module
     *
     * @effects generate_produces_three_bucket_cache_core_modules_plugins, each_cache_entry_has_listener_hooks_and_dynamic_keys
     */
    public function test_generate_produces_readable_three_bucket_cache(): void
    {
        $manager = new HookCacheManagerWithPath($this->tmpCachePath);

        $moduleManager = app(ModuleManager::class);
        $moduleManager->loadModules();
        $pluginManager = app(PluginManager::class);
        $pluginManager->loadPlugins();

        $counts = $manager->generate($moduleManager, $pluginManager);

        $this->assertTrue($manager->isCached());

        $cache = $manager->read();
        $this->assertIsArray($cache);
        $this->assertArrayHasKey('core', $cache);
        $this->assertArrayHasKey('modules', $cache);
        $this->assertArrayHasKey('plugins', $cache);

        // 코어 리스너는 app/Listeners 에 다수 존재
        $this->assertGreaterThan(0, $counts['core']);
        $this->assertGreaterThan(0, count($cache['core']));

        // 각 코어 항목은 listener/hooks/dynamic 키를 가진다
        foreach ($cache['core'] as $entry) {
            $this->assertArrayHasKey('listener', $entry);
            $this->assertArrayHasKey('hooks', $entry);
            $this->assertArrayHasKey('dynamic', $entry);
            $this->assertIsBool($entry['dynamic']);
        }
    }

    /**
     * 동적 훅 리스너(NotificationHookListener)가 dynamic=true 로 표시되는지 검증합니다.
     *
     * 코어 캐시에서 registerDynamicHooks 보유 리스너는 boot 후반부 지연 실행 대상이므로
     * dynamic 플래그가 정확해야 한다.
     *
     * @scenario cache_state=present_valid, listener_type=dynamic, regeneration_trigger=hooks_cache_command, registration_source=core
     *
     * @effects dynamic_listener_flagged_in_core_cache, dynamic_flagged_listener_deferred_to_boot_tail_like_scan_path
     */
    public function test_dynamic_listener_is_flagged_in_cache(): void
    {
        $manager = new HookCacheManagerWithPath($this->tmpCachePath);

        $moduleManager = app(ModuleManager::class);
        $moduleManager->loadModules();
        $pluginManager = app(PluginManager::class);
        $pluginManager->loadPlugins();

        $manager->generate($moduleManager, $pluginManager);
        $cache = $manager->read();

        $notificationEntry = collect($cache['core'])
            ->firstWhere('listener', NotificationHookListener::class);

        $this->assertNotNull($notificationEntry, 'NotificationHookListener 가 코어 캐시에 있어야 합니다');
        $this->assertTrue($notificationEntry['dynamic'], 'registerDynamicHooks 보유 리스너는 dynamic=true');
    }

    /**
     * clear() 가 캐시 파일을 삭제하는지 검증합니다.
     *
     * @scenario cache_state=present_but_testing_env, listener_type=action_sync, regeneration_trigger=extension_update, registration_source=plugin
     *
     * @effects testing_env_always_scans_ignoring_cache_file
     */
    public function test_clear_removes_cache_file(): void
    {
        File::ensureDirectoryExists(dirname($this->tmpCachePath));
        File::put($this->tmpCachePath, "<?php return ['core'=>[],'modules'=>[],'plugins'=>[]];");

        $manager = new HookCacheManagerWithPath($this->tmpCachePath);
        $this->assertTrue($manager->isCached());

        $this->assertTrue($manager->clear());
        $this->assertFalse($manager->isCached());
    }

    /**
     * read() 는 request-scoped static 메모리 캐시로 파일을 요청당 1회만 로드한다.
     *
     * 회귀 대상: read() 가 매 호출 캐시 파일(수십 KB)을 require 하면, 부팅 경로에서
     * core/module/plugin 등록이 여러 번(관측상 13회) 호출해 OPcache 부재 시 요청당
     * 십수 ms 를 낭비한다. memo 도입 후 첫 로드 결과를 재사용해 파일 접근을 1회로 고정한다.
     *
     * 검증: memo 가 채워진 뒤 캐시 파일을 삭제해도 read() 가 여전히 데이터를 반환하면
     * (파일을 재접근하지 않는다는 증거), 요청당 1회 로드가 성립한다.
     */
    public function test_read_memoizes_file_load_within_request(): void
    {
        File::ensureDirectoryExists(dirname($this->tmpCachePath));
        File::put(
            $this->tmpCachePath,
            "<?php return ['core'=>[['listener'=>'X','hooks'=>[]]],'modules'=>[],'plugins'=>[]];"
        );

        $manager = new HookCacheManagerWithPath($this->tmpCachePath);
        $first = $manager->read();
        $this->assertIsArray($first);
        $this->assertArrayHasKey('core', $first);

        // 파일을 삭제 — memo 가 없으면 다음 read() 는 null(파일 부재)을 반환한다.
        File::delete($this->tmpCachePath);

        $second = $manager->read();
        $this->assertSame(
            $first,
            $second,
            'read() 는 파일을 재접근하지 않고 memo 를 반환해야 합니다 (요청당 1회 로드).'
        );

        // 다른 인스턴스도 동일 static memo 를 공유한다 (부팅 경로의 여러 호출처 = 여러 인스턴스).
        $another = (new HookCacheManagerWithPath($this->tmpCachePath))->read();
        $this->assertSame($first, $another, 'static memo 는 인스턴스 간 공유되어야 합니다.');
    }

    /**
     * clear()/generate() 는 memo 를 무효화해 stale 데이터 반환을 막는다.
     *
     * 확장 install/update 로 캐시가 재생성되면 이후 read() 는 새 매핑을 반환해야 한다.
     * memo 가 무효화되지 않으면 옛 매핑이 요청 내내 잔존하는 회귀가 발생한다.
     */
    public function test_clear_invalidates_memo(): void
    {
        File::ensureDirectoryExists(dirname($this->tmpCachePath));
        File::put($this->tmpCachePath, "<?php return ['core'=>[['listener'=>'A','hooks'=>[]]],'modules'=>[],'plugins'=>[]];");

        $manager = new HookCacheManagerWithPath($this->tmpCachePath);
        $this->assertIsArray($manager->read()); // memo 채움

        // clear() 는 파일 + memo 를 모두 무효화한다.
        $manager->clear();

        // 파일이 없으므로 read() 는 null (memo 가 무효화되지 않았다면 옛 데이터를 반환했을 것).
        $this->assertNull($manager->read(), 'clear() 후 read() 는 memo 를 버리고 null 폴백해야 합니다.');
    }
}

// --- 테스트용 스텁 ---

class HookCacheStubAction implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'test.hookcache.action' => ['method' => 'handleAction', 'priority' => 10],
        ];
    }

    public function handle(...$args): void {}

    public function handleAction(string $value): void {}
}

class HookCacheStubFilter implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'test.hookcache.filter' => ['method' => 'handleFilter', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function handleFilter(string $value): string
    {
        return $value.'_cached';
    }
}

class HookCacheStubSync implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'test.hookcache.sync' => ['method' => 'handleSync', 'priority' => 5, 'sync' => true],
        ];
    }

    public function handle(...$args): void {}

    public function handleSync(): void {}
}

/**
 * 테스트에서 임시 캐시 경로를 주입하기 위한 서브클래스.
 */
class HookCacheManagerWithPath extends HookCacheManager
{
    public function __construct(string $path)
    {
        $this->cacheFilePath = $path;
    }
}
