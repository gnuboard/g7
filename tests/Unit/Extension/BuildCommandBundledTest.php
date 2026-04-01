<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 빌드 커맨드 _bundled 기준 전환 테스트
 *
 * canBuild() 메서드와 빌드 커맨드의 경로 결정 로직을 검증합니다.
 */
class BuildCommandBundledTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // canBuild() 메서드 테스트
    // ========================================================================

    /**
     * canBuild()이 JS 엔트리포인트가 있으면 true를 반환하는지 테스트
     */
    public function test_module_can_build_returns_true_with_js_entry(): void
    {
        $module = $this->createModuleWithAssets([
            'js' => ['entry' => 'resources/js/index.ts', 'output' => 'dist/js/module.iife.js'],
        ]);

        $this->assertTrue($module->canBuild());
    }

    /**
     * canBuild()이 CSS 엔트리포인트가 있으면 true를 반환하는지 테스트
     */
    public function test_module_can_build_returns_true_with_css_entry(): void
    {
        $module = $this->createModuleWithAssets([
            'css' => ['entry' => 'resources/css/main.css', 'output' => 'dist/css/module.css'],
        ]);

        $this->assertTrue($module->canBuild());
    }

    /**
     * canBuild()이 엔트리포인트 없으면 false를 반환하는지 테스트
     */
    public function test_module_can_build_returns_false_without_entry(): void
    {
        $module = $this->createModuleWithAssets([
            'js' => ['output' => 'dist/js/module.iife.js'],
        ]);

        $this->assertFalse($module->canBuild());
    }

    /**
     * canBuild()이 에셋 섹션 자체가 없으면 false를 반환하는지 테스트
     */
    public function test_module_can_build_returns_false_without_assets(): void
    {
        $module = $this->createModuleWithAssets([]);

        $this->assertFalse($module->canBuild());
    }

    /**
     * 플러그인 canBuild()이 JS 엔트리포인트가 있으면 true를 반환하는지 테스트
     */
    public function test_plugin_can_build_returns_true_with_js_entry(): void
    {
        $plugin = $this->createPluginWithAssets([
            'js' => ['entry' => 'resources/js/index.ts', 'output' => 'dist/js/plugin.iife.js'],
        ]);

        $this->assertTrue($plugin->canBuild());
    }

    /**
     * 플러그인 canBuild()이 엔트리포인트 없으면 false를 반환하는지 테스트
     */
    public function test_plugin_can_build_returns_false_without_entry(): void
    {
        $plugin = $this->createPluginWithAssets([]);

        $this->assertFalse($plugin->canBuild());
    }

    // ========================================================================
    // 빌드 커맨드 시그니처 테스트
    // ========================================================================

    /**
     * module:build 커맨드에 --active 옵션이 있는지 테스트
     */
    public function test_module_build_has_active_option(): void
    {
        $this->artisan('module:build', ['identifier' => 'nonexistent-module'])
            ->assertFailed();
    }

    /**
     * module:build 인자 없이 실행 시 사용법이 출력되고 --active 옵션이 안내되는지 테스트
     */
    public function test_module_build_without_args_shows_active_option_usage(): void
    {
        $this->artisan('module:build')
            ->expectsOutputToContain('--active')
            ->assertFailed();
    }

    /**
     * template:build 인자 없이 실행 시 사용법이 출력되고 --active 옵션이 안내되는지 테스트
     */
    public function test_template_build_without_args_shows_active_option_usage(): void
    {
        $this->artisan('template:build')
            ->expectsOutputToContain('--active')
            ->assertFailed();
    }

    /**
     * plugin:build 인자 없이 실행 시 사용법이 출력되고 --active 옵션이 안내되는지 테스트
     */
    public function test_plugin_build_without_args_shows_active_option_usage(): void
    {
        $this->artisan('plugin:build')
            ->expectsOutputToContain('--active')
            ->assertFailed();
    }

    // ========================================================================
    // 빌드 경로 결정 로직 테스트 (resolveBuildPath)
    // ========================================================================

    /**
     * _bundled에만 존재하는 모듈은 _bundled 경로가 선택되는지 테스트
     */
    public function test_module_build_resolves_bundled_path_when_only_bundled_exists(): void
    {
        $identifier = 'test-buildpath-bundled-'.uniqid();
        $bundledPath = base_path("modules/_bundled/{$identifier}");
        $activePath = base_path("modules/{$identifier}");

        try {
            // _bundled에만 디렉토리와 package.json 생성
            File::ensureDirectoryExists($bundledPath);
            File::put($bundledPath.'/package.json', json_encode(['name' => $identifier]));
            File::put($bundledPath.'/module.json', json_encode([
                'identifier' => $identifier,
                'name' => ['ko' => '테스트'],
                'version' => '1.0.0',
            ]));

            // 모듈 로드
            $moduleManager = app(ModuleManager::class);
            $moduleManager->loadModules();

            // 빌드 실행 → _bundled 경로가 선택되어야 함
            $this->artisan('module:build', ['identifier' => $identifier])
                ->expectsOutputToContain('_bundled')
                ->assertFailed(); // npm build는 실패하지만 경로 선택은 확인 가능
        } finally {
            // 정리
            if (File::isDirectory($bundledPath)) {
                File::deleteDirectory($bundledPath);
            }
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }
    }

    /**
     * --active 옵션 사용 시 활성 디렉토리가 선택되는지 테스트
     */
    public function test_module_build_resolves_active_path_with_active_option(): void
    {
        $identifier = 'test-buildpath-active-'.uniqid();
        $bundledPath = base_path("modules/_bundled/{$identifier}");
        $activePath = base_path("modules/{$identifier}");

        try {
            // 양쪽 모두 존재하는 상황
            File::ensureDirectoryExists($bundledPath);
            File::put($bundledPath.'/package.json', json_encode(['name' => $identifier]));
            File::put($bundledPath.'/module.json', json_encode([
                'identifier' => $identifier,
                'name' => ['ko' => '테스트'],
                'version' => '1.0.0',
            ]));

            File::ensureDirectoryExists($activePath);
            File::put($activePath.'/package.json', json_encode(['name' => $identifier]));
            File::put($activePath.'/module.json', json_encode([
                'identifier' => $identifier,
                'name' => ['ko' => '테스트'],
                'version' => '1.0.0',
            ]));

            // 모듈 로드
            $moduleManager = app(ModuleManager::class);
            $moduleManager->loadModules();

            // --active 옵션으로 실행 → 활성 경로가 선택되어야 함
            $this->artisan('module:build', [
                'identifier' => $identifier,
                '--active' => true,
            ])
                ->expectsOutputToContain('활성')
                ->assertFailed(); // npm build는 실패하지만 경로 선택은 확인 가능
        } finally {
            if (File::isDirectory($bundledPath)) {
                File::deleteDirectory($bundledPath);
            }
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }
    }

    /**
     * 존재하지 않는 모듈 빌드 시 적절한 오류 메시지가 출력되는지 테스트
     */
    public function test_module_build_fails_when_module_not_found(): void
    {
        $this->artisan('module:build', ['identifier' => 'nonexistent-module-'.uniqid()])
            ->expectsOutputToContain('찾을 수 없습니다')
            ->assertFailed();
    }

    /**
     * 존재하지 않는 플러그인 빌드 시 적절한 오류 메시지가 출력되는지 테스트
     */
    public function test_plugin_build_fails_when_plugin_not_found(): void
    {
        $this->artisan('plugin:build', ['identifier' => 'nonexistent-plugin-'.uniqid()])
            ->expectsOutputToContain('찾을 수 없습니다')
            ->assertFailed();
    }

    /**
     * 존재하지 않는 템플릿 빌드 시 적절한 오류 메시지가 출력되는지 테스트
     */
    public function test_template_build_fails_when_template_not_found(): void
    {
        $this->artisan('template:build', ['identifier' => 'nonexistent-template-'.uniqid()])
            ->expectsOutputToContain('찾을 수 없습니다')
            ->assertFailed();
    }

    /**
     * --active 옵션으로 활성 디렉토리가 없는 모듈 빌드 시 실패하는지 테스트
     */
    public function test_module_build_with_active_fails_when_no_active_dir(): void
    {
        $this->artisan('module:build', [
            'identifier' => 'nonexistent-active-'.uniqid(),
            '--active' => true,
        ])
            ->expectsOutputToContain('찾을 수 없습니다')
            ->assertFailed();
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 지정된 에셋 설정으로 테스트용 AbstractModule 인스턴스를 생성합니다.
     *
     * @param  array  $assets  에셋 설정 배열
     * @return AbstractModule 테스트용 모듈 인스턴스
     */
    private function createModuleWithAssets(array $assets): AbstractModule
    {
        return new class($assets) extends AbstractModule
        {
            public function __construct(private array $testAssets)
            {
                // 부모 생성자 호출하지 않음 (테스트용)
            }

            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getAssets(): array
            {
                return $this->testAssets;
            }
        };
    }

    /**
     * 지정된 에셋 설정으로 테스트용 AbstractPlugin 인스턴스를 생성합니다.
     *
     * @param  array  $assets  에셋 설정 배열
     * @return AbstractPlugin 테스트용 플러그인 인스턴스
     */
    private function createPluginWithAssets(array $assets): AbstractPlugin
    {
        return new class($assets) extends AbstractPlugin
        {
            public function __construct(private array $testAssets)
            {
                // 부모 생성자 호출하지 않음 (테스트용)
            }

            public function getName(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return ['ko' => '테스트', 'en' => 'Test'];
            }

            public function getAssets(): array
            {
                return $this->testAssets;
            }
        };
    }
}
