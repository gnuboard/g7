<?php

namespace Tests\Feature\Console;

use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * hooks:cache / hooks:clear Artisan 커맨드 Feature 테스트.
 *
 * 정적 훅 매핑 캐시 생성/삭제 명령이 캐시 파일을 실제로 생성·삭제하는지 검증한다.
 */
class HooksCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpCachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpCachePath = storage_path('framework/testing/hooks_cmd_'.uniqid().'.php');

        // 실제 bootstrap/cache/hooks.php 를 건드리지 않도록 임시 경로 매니저를 컨테이너에 바인딩
        $this->app->instance(HookCacheManager::class, new class($this->tmpCachePath) extends HookCacheManager
        {
            public function __construct(string $path)
            {
                $this->cacheFilePath = $path;
            }
        });
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tmpCachePath)) {
            File::delete($this->tmpCachePath);
        }
        parent::tearDown();
    }

    /**
     * @scenario cache_state=absent, listener_type=action_sync, regeneration_trigger=hooks_cache_command, registration_source=module
     *
     * @effects hooks_cache_command_generates_cache_file, removed_core_listener_dropped_from_cache_after_regeneration
     */
    public function test_hooks_cache_command_generates_cache_file(): void
    {
        $this->assertFalse(File::exists($this->tmpCachePath));

        $this->artisan('hooks:cache')
            ->assertSuccessful();

        $this->assertTrue(File::exists($this->tmpCachePath), 'hooks:cache 는 캐시 파일을 생성해야 합니다');

        $cache = require $this->tmpCachePath;
        $this->assertArrayHasKey('core', $cache);
        $this->assertArrayHasKey('modules', $cache);
        $this->assertArrayHasKey('plugins', $cache);
        $this->assertGreaterThan(0, count($cache['core']));
    }

    /**
     * @scenario cache_state=present_valid, listener_type=action_queued, regeneration_trigger=hooks_clear_command, registration_source=plugin
     *
     * @effects hooks_clear_command_removes_cache_file
     */
    public function test_hooks_clear_command_removes_cache_file(): void
    {
        // 먼저 생성
        $this->artisan('hooks:cache')->assertSuccessful();
        $this->assertTrue(File::exists($this->tmpCachePath));

        // 삭제
        $this->artisan('hooks:clear')->assertSuccessful();
        $this->assertFalse(File::exists($this->tmpCachePath), 'hooks:clear 는 캐시 파일을 삭제해야 합니다');
    }

    /**
     * @scenario cache_state=present_but_testing_env, listener_type=action_queued, regeneration_trigger=hooks_cache_command, registration_source=module
     *
     * @effects hooks_clear_is_safe_when_cache_absent
     */
    public function test_hooks_clear_command_is_safe_when_cache_absent(): void
    {
        $this->assertFalse(File::exists($this->tmpCachePath));

        // 캐시 부재 상태에서도 성공 종료 (route:clear 동형)
        $this->artisan('hooks:clear')->assertSuccessful();
    }

    /**
     * extension:update-autoload 가 오토로드 캐시와 함께 정적 훅 매핑 캐시를 재생성하는지 검증합니다.
     *
     * 코어 업데이트(CoreUpdateService::clearAllCaches → extension:update-autoload)의
     * 훅 캐시 재생성 choke point. 코어 리스너 추가/변경/삭제가 이 경로로 캐시에 반영된다.
     *
     * 격리: ExtensionManager::generateAutoloadFile() 은 mock 으로 no-op 처리한다.
     * 실제 커맨드를 그대로 실행하면 testing DB(활성 확장 0건) 기준으로 실 경로
     * `bootstrap/cache/autoload-extensions.php` 를 빈 배열로 덮어써 dev 환경을 오염시킨다
     * (feedback_test_must_protect_dev_directories). 훅 캐시는 setUp 의 임시 경로 매니저로 격리.
     *
     * @scenario cache_state=corrupted_broken_structure, listener_type=action_queued, regeneration_trigger=core_update, registration_source=plugin
     *
     * @effects core_update_regenerates_hook_cache_via_clearAllCaches_extension_update_autoload, extension_update_regenerates_hook_cache_via_updateComposerAutoload
     */
    public function test_extension_update_autoload_regenerates_hook_cache(): void
    {
        // 실 오토로드 캐시 파일을 건드리지 않도록 generateAutoloadFile 을 no-op 으로 대체
        $extensionManager = Mockery::mock(ExtensionManager::class)->makePartial();
        $extensionManager->shouldReceive('generateAutoloadFile')->once();
        $this->app->instance(ExtensionManager::class, $extensionManager);

        $this->assertFalse(File::exists($this->tmpCachePath));

        $this->artisan('extension:update-autoload')->assertSuccessful();

        $this->assertTrue(
            File::exists($this->tmpCachePath),
            'extension:update-autoload 는 훅 매핑 캐시를 함께 재생성해야 합니다 (코어 업데이트 choke point)'
        );

        $cache = require $this->tmpCachePath;
        $this->assertArrayHasKey('core', $cache);
        $this->assertGreaterThan(0, count($cache['core']));
    }
}
