<?php

namespace Tests\Unit\Extension;

use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 테스트 환경에서 실제 훅 캐시 파일(`bootstrap/cache/hooks.php`)을 쓰거나 지우지 않는다.
 *
 * 2026-11-03 실측: `RunUpgradeStepsAbstractGuardTest` 가 실제 업그레이드 단계를 끝까지 돌리면서 7.1.3 단계의
 * `extension:update-autoload` 가 개발 사이트 훅 캐시를 시험 DB 기준(확장 0건·가짜 코어 버전 7.9.9)으로 덮었다.
 * 같은 부류로 `HookCacheManagerTest`·`ExtensionUpdateRollbackCacheRepairTest` 는 setUp 마다 개발 훅 캐시를 지웠다.
 * 테스트마다 목으로 막던 것을 관리자 한 곳에서 막는다 — 오토로드 캐시(`ExtensionManager::generateAutoloadFile`)와 같은 방식.
 * 경로를 임시 경로로 바꾼 관리자는 생성 로직 검증용이므로 그대로 쓴다.
 */
class HookCacheRealFileGuardTest extends TestCase
{
    /** @var string|null 시험 전 실제 훅 캐시 내용(null = 없었음) */
    private ?string $original = null;

    /** @var string 실제 훅 캐시 경로 */
    private string $realPath;

    /**
     * 실제 훅 캐시를 보관합니다(가드가 깨져 있어도 시험 뒤 원래대로 되돌리기 위해).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->realPath = base_path('bootstrap/cache/hooks.php');
        $this->original = is_file($this->realPath) ? File::get($this->realPath) : null;
    }

    /**
     * 실제 훅 캐시를 시험 전 상태로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        (new HookCacheManager)->clear();
        if ($this->original === null) {
            File::delete($this->realPath);
        } else {
            File::put($this->realPath, $this->original);
        }
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 테스트 환경의 generate() 는 실제 훅 캐시 파일을 바꾸지 않는다.
     *
     * @scenario cache_state=present_but_testing_env, registration_source=module, listener_type=action_sync, regeneration_trigger=hooks_cache_command
     *
     * @effects testing_env_never_writes_real_hook_cache_file
     *
     * @return void 반환값 없음
     */
    public function test_generate_does_not_write_real_cache_file_in_testing(): void
    {
        $marker = "<?php\n// real-file-guard marker\nreturn ['core' => [], 'modules' => [], 'plugins' => []];\n";
        File::put($this->realPath, $marker);

        $counts = (new HookCacheManager)->generate($this->moduleManager(), $this->pluginManager());

        $this->assertSame($marker, File::get($this->realPath), '테스트가 개발 사이트 훅 캐시를 시험 DB 기준으로 덮었다');
        $this->assertSame(0, $counts['modules']);
    }

    /**
     * 테스트 환경의 clear() 는 실제 훅 캐시 파일을 지우지 않는다(메모만 비운다).
     *
     * @scenario cache_state=present_but_testing_env, registration_source=plugin, listener_type=filter, regeneration_trigger=hooks_clear_command
     *
     * @effects testing_env_never_deletes_real_hook_cache_file
     *
     * @return void 반환값 없음
     */
    public function test_clear_does_not_delete_real_cache_file_in_testing(): void
    {
        $marker = "<?php\n// real-file-guard marker\nreturn ['core' => [], 'modules' => [], 'plugins' => []];\n";
        File::put($this->realPath, $marker);

        $this->assertTrue((new HookCacheManager)->clear());

        $this->assertFileExists($this->realPath, '테스트가 개발 사이트 훅 캐시를 지웠다');
        $this->assertSame($marker, File::get($this->realPath));
    }

    /**
     * 경로를 바꾼 관리자는 테스트 환경에서도 그 경로에 쓰고 지운다(생성 로직 검증 경로 유지).
     *
     * @scenario cache_state=absent, registration_source=module, listener_type=action_queued, regeneration_trigger=hooks_cache_command
     *
     * @effects substituted_path_manager_still_writes_and_clears_in_testing
     *
     * @return void 반환값 없음
     */
    public function test_substituted_path_manager_still_writes_and_clears(): void
    {
        $path = storage_path('framework/testing/hooks_guard_'.uniqid().'.php');
        $manager = new HookCacheManager;
        (fn () => $this->cacheFilePath = $path)->call($manager);

        try {
            $manager->generate($this->moduleManager(), $this->pluginManager());
            $this->assertFileExists($path);

            $this->assertTrue($manager->clear());
            $this->assertFileDoesNotExist($path);
        } finally {
            File::delete($path);
        }
    }

    /**
     * 활성 모듈이 없는 모듈 관리자 목.
     *
     * @return ModuleManager 목
     */
    private function moduleManager(): ModuleManager
    {
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getActiveModules')->andReturn([]);

        return $manager;
    }

    /**
     * 활성 플러그인이 없는 플러그인 관리자 목.
     *
     * @return PluginManager 목
     */
    private function pluginManager(): PluginManager
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('getActivePlugins')->andReturn([]);

        return $manager;
    }
}
