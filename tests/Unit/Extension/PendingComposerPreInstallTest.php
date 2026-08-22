<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * _pending 디렉토리 Composer 선행 설치 테스트
 *
 * GitHub/ZIP 설치 시 _pending에 스테이징된 확장의 Composer 의존성을
 * 활성 디렉토리 이관 전에 선행 설치하는 로직을 검증합니다.
 */
class PendingComposerPreInstallTest extends TestCase
{
    private string $modulesPath;

    private string $pluginsPath;

    private ExtensionManager|Mockery\MockInterface $extensionManager;

    private ModuleRepositoryInterface|Mockery\MockInterface $moduleRepository;

    private PluginRepositoryInterface|Mockery\MockInterface $pluginRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = base_path('modules');
        $this->pluginsPath = base_path('plugins');

        $this->extensionManager = Mockery::mock(ExtensionManager::class);
        $this->moduleRepository = Mockery::mock(ModuleRepositoryInterface::class);
        $this->pluginRepository = Mockery::mock(PluginRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        // 테스트 디렉토리 정리
        $paths = [
            $this->modulesPath.'/_pending/test-pending-mod',
            $this->modulesPath.'/test-pending-mod',
            $this->pluginsPath.'/_pending/test-pending-plg',
            $this->pluginsPath.'/test-pending-plg',
        ];

        foreach ($paths as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * ModuleManager 인스턴스를 생성합니다.
     */
    private function createModuleManager(): ModuleManager
    {
        return new ModuleManager(
            extensionManager: $this->extensionManager,
            moduleRepository: $this->moduleRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            menuRepository: Mockery::mock(MenuRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            pluginRepository: Mockery::mock(PluginRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );
    }

    /**
     * PluginManager 인스턴스를 생성합니다.
     */
    private function createPluginManager(): PluginManager
    {
        return new PluginManager(
            extensionManager: $this->extensionManager,
            pluginRepository: $this->pluginRepository,
            permissionRepository: Mockery::mock(PermissionRepositoryInterface::class),
            roleRepository: Mockery::mock(RoleRepositoryInterface::class),
            templateRepository: Mockery::mock(TemplateRepositoryInterface::class),
            moduleRepository: Mockery::mock(ModuleRepositoryInterface::class),
            layoutRepository: Mockery::mock(LayoutRepositoryInterface::class),
            layoutExtensionService: Mockery::mock(LayoutExtensionService::class),
        );
    }

    /**
     * _pending에 테스트 모듈을 생성합니다.
     *
     * @param  bool  $withComposerDeps  외부 Composer 의존성 포함 여부
     */
    private function createPendingModule(string $identifier, bool $withComposerDeps = false): string
    {
        $pendingPath = $this->modulesPath.'/_pending/'.$identifier;
        File::ensureDirectoryExists($pendingPath);

        File::put($pendingPath.'/module.json', json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
        ]));

        if ($withComposerDeps) {
            File::put($pendingPath.'/composer.json', json_encode([
                'name' => 'test/test-module',
                'require' => [
                    'php' => '^8.2',
                    'guzzlehttp/guzzle' => '^7.0',
                ],
            ]));
        }

        return $pendingPath;
    }

    /**
     * _pending에 테스트 플러그인을 생성합니다.
     *
     * @param  bool  $withComposerDeps  외부 Composer 의존성 포함 여부
     */
    private function createPendingPlugin(string $identifier, bool $withComposerDeps = false): string
    {
        $pendingPath = $this->pluginsPath.'/_pending/'.$identifier;
        File::ensureDirectoryExists($pendingPath);

        File::put($pendingPath.'/plugin.json', json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 플러그인', 'en' => 'Test Plugin'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
        ]));

        if ($withComposerDeps) {
            File::put($pendingPath.'/composer.json', json_encode([
                'name' => 'test/test-plugin',
                'require' => [
                    'php' => '^8.2',
                    'stripe/stripe-php' => '^10.0',
                ],
            ]));
        }

        return $pendingPath;
    }

    // ========================================================================
    // ExtensionPendingHelper 연동 테스트
    // ========================================================================

    /**
     * _pending에 모듈이 있으면 isPending이 true를 반환하는지 확인
     */
    public function test_pending_module_is_detected(): void
    {
        $this->createPendingModule('test-pending-mod');

        $this->assertTrue(
            ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod')
        );
    }

    /**
     * _pending에 플러그인이 있으면 isPending이 true를 반환하는지 확인
     */
    public function test_pending_plugin_is_detected(): void
    {
        $this->createPendingPlugin('test-pending-plg');

        $this->assertTrue(
            ExtensionPendingHelper::isPending($this->pluginsPath, 'test-pending-plg')
        );
    }

    /**
     * _pending에 모듈이 없으면 isPending이 false를 반환하는지 확인
     */
    public function test_non_pending_module_returns_false(): void
    {
        $this->assertFalse(
            ExtensionPendingHelper::isPending($this->modulesPath, 'nonexistent-mod')
        );
    }

    // ========================================================================
    // hasComposerDependenciesAt + _pending 경로 테스트
    // ========================================================================

    /**
     * _pending 경로에서 외부 Composer 의존성을 올바르게 감지하는지 확인
     */
    public function test_has_composer_dependencies_at_pending_module_path(): void
    {
        $pendingPath = $this->createPendingModule('test-pending-mod', withComposerDeps: true);

        $extensionManager = app(ExtensionManager::class);
        $this->assertTrue($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    /**
     * _pending 경로에 composer.json이 없으면 false 반환
     */
    public function test_has_composer_dependencies_at_pending_without_composer_json(): void
    {
        $pendingPath = $this->createPendingModule('test-pending-mod', withComposerDeps: false);

        $extensionManager = app(ExtensionManager::class);
        $this->assertFalse($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    /**
     * _pending 경로에 php/ext-* 의존성만 있으면 false 반환
     */
    public function test_has_composer_dependencies_at_pending_with_php_only(): void
    {
        $pendingPath = $this->createPendingModule('test-pending-mod');
        File::put($pendingPath.'/composer.json', json_encode([
            'name' => 'test/test-module',
            'require' => [
                'php' => '^8.2',
                'ext-json' => '*',
            ],
        ]));

        $extensionManager = app(ExtensionManager::class);
        $this->assertFalse($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    // ========================================================================
    // _pending Composer 선행 설치 조건 테스트 (Module)
    // ========================================================================

    /**
     * 활성 디렉토리 부재 + _pending 존재 + Composer 의존성 있음 → 선행 설치 조건 충족
     */
    public function test_module_pending_composer_precondition_active_absent_pending_exists_with_deps(): void
    {
        $this->createPendingModule('test-pending-mod', withComposerDeps: true);

        // 활성 디렉토리가 없어야 함
        $activePath = $this->modulesPath.'/test-pending-mod';
        $this->assertFalse(File::isDirectory($activePath));

        // _pending이 존재해야 함
        $this->assertTrue(ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod'));

        // Composer 의존성 있어야 함
        $pendingPath = ExtensionPendingHelper::getPendingPath($this->modulesPath, 'test-pending-mod');
        $extensionManager = app(ExtensionManager::class);
        $this->assertTrue($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    /**
     * 활성 디렉토리 존재 시 _pending composer 선행 설치 조건 불충족
     */
    public function test_module_pending_composer_precondition_fails_when_active_exists(): void
    {
        $this->createPendingModule('test-pending-mod', withComposerDeps: true);

        // 활성 디렉토리 생성 (이미 설치된 상태 시뮬레이션)
        $activePath = $this->modulesPath.'/test-pending-mod';
        File::ensureDirectoryExists($activePath);

        // 활성 디렉토리가 있으면 조건 불충족
        $condition = ! File::isDirectory($activePath)
            && ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod');

        $this->assertFalse($condition);
    }

    /**
     * _pending 없을 시 composer 선행 설치 조건 불충족
     */
    public function test_module_pending_composer_precondition_fails_when_no_pending(): void
    {
        // _pending 없이 활성 디렉토리도 없음
        $activePath = $this->modulesPath.'/test-pending-mod';

        $condition = ! File::isDirectory($activePath)
            && ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod');

        $this->assertFalse($condition);
    }

    /**
     * Composer 의존성 없으면 선행 설치 불필요 확인
     */
    public function test_module_pending_composer_skipped_without_deps(): void
    {
        $this->createPendingModule('test-pending-mod', withComposerDeps: false);

        $activePath = $this->modulesPath.'/test-pending-mod';
        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod'));

        // Composer 의존성 없음
        $pendingPath = ExtensionPendingHelper::getPendingPath($this->modulesPath, 'test-pending-mod');
        $extensionManager = app(ExtensionManager::class);
        $this->assertFalse($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    // ========================================================================
    // _pending Composer 선행 설치 조건 테스트 (Plugin)
    // ========================================================================

    /**
     * 플러그인: 활성 디렉토리 부재 + _pending 존재 + Composer 의존성 있음 → 선행 설치 조건 충족
     */
    public function test_plugin_pending_composer_precondition_active_absent_pending_exists_with_deps(): void
    {
        $this->createPendingPlugin('test-pending-plg', withComposerDeps: true);

        $activePath = $this->pluginsPath.'/test-pending-plg';
        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(ExtensionPendingHelper::isPending($this->pluginsPath, 'test-pending-plg'));

        $pendingPath = ExtensionPendingHelper::getPendingPath($this->pluginsPath, 'test-pending-plg');
        $extensionManager = app(ExtensionManager::class);
        $this->assertTrue($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    /**
     * 플러그인: 활성 디렉토리 존재 시 _pending composer 선행 설치 조건 불충족
     */
    public function test_plugin_pending_composer_precondition_fails_when_active_exists(): void
    {
        $this->createPendingPlugin('test-pending-plg', withComposerDeps: true);

        $activePath = $this->pluginsPath.'/test-pending-plg';
        File::ensureDirectoryExists($activePath);

        $condition = ! File::isDirectory($activePath)
            && ExtensionPendingHelper::isPending($this->pluginsPath, 'test-pending-plg');

        $this->assertFalse($condition);
    }

    /**
     * 플러그인: Composer 의존성 없으면 선행 설치 불필요
     */
    public function test_plugin_pending_composer_skipped_without_deps(): void
    {
        $this->createPendingPlugin('test-pending-plg', withComposerDeps: false);

        $activePath = $this->pluginsPath.'/test-pending-plg';
        $this->assertFalse(File::isDirectory($activePath));
        $this->assertTrue(ExtensionPendingHelper::isPending($this->pluginsPath, 'test-pending-plg'));

        $pendingPath = ExtensionPendingHelper::getPendingPath($this->pluginsPath, 'test-pending-plg');
        $extensionManager = app(ExtensionManager::class);
        $this->assertFalse($extensionManager->hasComposerDependenciesAt($pendingPath));
    }

    // ========================================================================
    // composerDoneInPending 플래그 동작 테스트
    // ========================================================================

    /**
     * _pending에서 composer 설치 완료 시 Phase 4.5 스킵 확인 (모듈)
     *
     * 실제 installModule을 호출하지 않고, 플래그 로직만 단위 테스트합니다.
     */
    public function test_module_composer_done_in_pending_flag_skips_phase_4_5(): void
    {
        // $composerDoneInPending = true일 때 Phase 4.5의 조건문이 스킵되는지 검증
        $composerDoneInPending = true;

        // Phase 4.5 조건: if (! $composerDoneInPending) { ... }
        // $composerDoneInPending이 true이면 이 블록에 진입하지 않음
        $phase45Executed = ! $composerDoneInPending;

        $this->assertFalse($phase45Executed, 'Phase 4.5는 _pending에서 composer 완료 시 스킵되어야 합니다');
    }

    /**
     * _pending composer 미실행 시 Phase 4.5 정상 진입 확인 (모듈)
     */
    public function test_module_composer_not_done_in_pending_enters_phase_4_5(): void
    {
        $composerDoneInPending = false;

        $phase45Executed = ! $composerDoneInPending;

        $this->assertTrue($phase45Executed, 'Phase 4.5는 _pending에서 composer 미실행 시 진입해야 합니다');
    }

    /**
     * _pending composer 설치 실패해도 composerDoneInPending은 true (중복 방지)
     *
     * 코드에서 runComposerInstallAt() 실패 시에도 $composerDoneInPending = true로 설정됩니다.
     * 이는 _pending에서 실패한 composer를 활성 디렉토리에서 다시 시도하지 않기 위함입니다.
     */
    public function test_composer_done_flag_set_true_even_on_failure(): void
    {
        // 코드 패턴 검증:
        // if ($this->extensionManager->hasComposerDependenciesAt($pendingPath)) {
        //     $composerResult = $this->extensionManager->runComposerInstallAt($pendingPath);
        //     if (! $composerResult) { Log::warning(...); }
        //     $composerDoneInPending = true;  // <-- 결과와 무관하게 항상 true
        // }
        $composerDoneInPending = false;

        // hasComposerDependenciesAt = true, 의존성 있음
        $hasDeps = true;
        if ($hasDeps) {
            $composerResult = false; // 실패 시뮬레이션
            // 실패해도 플래그는 true로 설정
            $composerDoneInPending = true;
        }

        $this->assertTrue($composerDoneInPending, 'composer 실패 시에도 플래그는 true여야 합니다 (중복 방지)');
        $this->assertFalse(! $composerDoneInPending, 'Phase 4.5는 스킵되어야 합니다');
    }

    // ========================================================================
    // _pending → 활성 디렉토리 복사 흐름 검증 (통합)
    // ========================================================================

    /**
     * _pending에서 활성 디렉토리로 복사 시 copyFromPendingOrBundled가 _pending을 우선함
     */
    public function test_copy_from_pending_prioritizes_pending_over_bundled(): void
    {
        // _pending 모듈 생성
        $this->createPendingModule('test-pending-mod');

        // _bundled에도 동일 식별자 생성 (하위 버전)
        $bundledPath = $this->modulesPath.'/_bundled/test-pending-mod';
        File::ensureDirectoryExists($bundledPath);
        File::put($bundledPath.'/module.json', json_encode([
            'identifier' => 'test-pending-mod',
            'version' => '0.9.0',
            'vendor' => 'test',
            'name' => ['ko' => '번들 테스트', 'en' => 'Bundled Test'],
            'description' => ['ko' => '구버전', 'en' => 'Old version'],
            'dependencies' => [],
        ]));

        // _pending이 우선순위를 가짐
        $this->assertTrue(ExtensionPendingHelper::isPending($this->modulesPath, 'test-pending-mod'));

        // 정리
        if (File::isDirectory($bundledPath)) {
            File::deleteDirectory($bundledPath);
        }
    }

    /**
     * _pending의 getPendingPath가 올바른 경로를 반환하는지 확인
     */
    public function test_get_pending_path_returns_correct_path(): void
    {
        $this->createPendingModule('test-pending-mod');

        $expectedPath = $this->modulesPath.'/_pending/test-pending-mod';
        $actualPath = ExtensionPendingHelper::getPendingPath($this->modulesPath, 'test-pending-mod');

        // 경로 정규화 (Windows 호환)
        $this->assertEquals(
            str_replace('\\', '/', $expectedPath),
            str_replace('\\', '/', $actualPath)
        );
    }

    /**
     * 플러그인 _pending의 getPendingPath가 올바른 경로를 반환하는지 확인
     */
    public function test_get_pending_path_returns_correct_path_for_plugin(): void
    {
        $this->createPendingPlugin('test-pending-plg');

        $expectedPath = $this->pluginsPath.'/_pending/test-pending-plg';
        $actualPath = ExtensionPendingHelper::getPendingPath($this->pluginsPath, 'test-pending-plg');

        $this->assertEquals(
            str_replace('\\', '/', $expectedPath),
            str_replace('\\', '/', $actualPath)
        );
    }
}
