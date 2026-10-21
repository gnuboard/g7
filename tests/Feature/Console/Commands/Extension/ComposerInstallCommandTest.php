<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Composer 의존성 설치 Artisan 커맨드 테스트
 *
 * module:composer-install, plugin:composer-install, extension:composer-install
 * 커맨드의 기본 동작을 검증합니다.
 *
 * ⚠️ 주의: 실제 composer install은 실행하지 않습니다.
 * 외부 패키지가 없는 모듈/플러그인에 대한 스킵 동작과
 * 존재하지 않는 확장에 대한 에러 처리를 검증합니다.
 */
class ComposerInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 모듈·플러그인 매니저가 확장 목록을 읽어 두게 초기화한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈/플러그인 매니저 초기화
        $moduleManager = app(ModuleManager::class);
        $moduleManager->loadModules();

        $pluginManager = app(PluginManager::class);
        $pluginManager->loadPlugins();
    }

    // ========================================================================
    // module:composer-install 테스트
    // ========================================================================

    /**
     * 식별자와 --all 모두 생략 시 사용법을 표시합니다.
     *
     * @scenario command=module
     *
     * @effects composer_install_command_shows_usage_without_identifier
     *
     * @return void 반환값 없음
     */
    public function test_module_composer_install_shows_usage_without_arguments(): void
    {
        $this->artisan('module:composer-install')
            ->assertExitCode(1)
            ->expectsOutputToContain(__('modules.commands.composer_install.missing_identifier'));
    }

    /**
     * 존재하지 않는 모듈에 대해 에러를 출력합니다.
     *
     * @scenario command=module
     *
     * @effects composer_install_command_fails_for_unknown_extension
     *
     * @return void 반환값 없음
     */
    public function test_module_composer_install_fails_for_nonexistent_module(): void
    {
        $this->artisan('module:composer-install', ['identifier' => 'nonexistent-module'])
            ->assertExitCode(1);
    }

    /**
     * 외부 의존성이 없는 모듈은 composer install 을 부르지 않고 안내만 남긴 채 성공 종료합니다.
     *
     * 활성 디렉터리(`modules/`)에 어떤 모듈이 깔려 있는지는 환경마다 다르므로, 모듈 조회와 의존성 판정은
     * 대역으로 고정하고 명령의 분기(안내 문구·성공 종료·설치 미호출)만 잰다.
     *
     * @scenario command=module
     *
     * @effects composer_install_command_skips_extension_without_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_module_composer_install_skips_module_without_dependencies(): void
    {
        $identifier = 'probe-no_deps';

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('loadModules')->andReturnNull();
        $moduleManager->shouldReceive('getModule')->with($identifier)->andReturn(Mockery::mock(ModuleInterface::class));
        $this->app->instance(ModuleManager::class, $moduleManager);

        $extensionManager = Mockery::mock(ExtensionManager::class);
        $extensionManager->shouldReceive('hasComposerDependencies')->once()->with('modules', $identifier)->andReturnFalse();
        $extensionManager->shouldNotReceive('runComposerInstall');
        $extensionManager->shouldNotReceive('updateComposerAutoload');
        $this->app->instance(ExtensionManager::class, $extensionManager);

        $this->artisan('module:composer-install', ['identifier' => $identifier])
            ->expectsOutputToContain(__('modules.composer_install.no_dependencies', ['module' => $identifier]))
            ->assertExitCode(0);
    }

    // ========================================================================
    // plugin:composer-install 테스트
    // ========================================================================

    /**
     * 식별자와 --all 모두 생략 시 사용법을 표시합니다.
     *
     * @scenario command=plugin
     *
     * @effects composer_install_command_shows_usage_without_identifier
     *
     * @return void 반환값 없음
     */
    public function test_plugin_composer_install_shows_usage_without_arguments(): void
    {
        $this->artisan('plugin:composer-install')
            ->assertExitCode(1)
            ->expectsOutputToContain(__('plugins.commands.composer_install.missing_identifier'));
    }

    /**
     * 존재하지 않는 플러그인에 대해 에러를 출력합니다.
     *
     * @scenario command=plugin
     *
     * @effects composer_install_command_fails_for_unknown_extension
     *
     * @return void 반환값 없음
     */
    public function test_plugin_composer_install_fails_for_nonexistent_plugin(): void
    {
        $this->artisan('plugin:composer-install', ['identifier' => 'nonexistent-plugin'])
            ->assertExitCode(1);
    }

    // ========================================================================
    // extension:composer-install 테스트
    // ========================================================================

    /**
     * extension:composer-install이 module과 plugin 커맨드를 모두 호출합니다.
     *
     * @scenario command=extension
     *
     * @effects extension_composer_install_runs_module_and_plugin_steps
     *
     * @return void 반환값 없음
     */
    public function test_extension_composer_install_runs_both_module_and_plugin(): void
    {
        $this->artisan('extension:composer-install')
            ->assertExitCode(0)
            ->expectsOutputToContain('모듈')
            ->expectsOutputToContain('플러그인');
    }

    /**
     * --no-dev 옵션이 정상적으로 전달됩니다.
     *
     * @scenario command=extension
     *
     * @effects extension_composer_install_accepts_no_dev_option
     *
     * @return void 반환값 없음
     */
    public function test_extension_composer_install_passes_no_dev_option(): void
    {
        $this->artisan('extension:composer-install', ['--no-dev' => true])
            ->assertExitCode(0);
    }
}
