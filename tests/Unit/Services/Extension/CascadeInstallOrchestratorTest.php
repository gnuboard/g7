<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * 확장 연쇄 설치 서비스 검증 — 의존의 의존·순환·버전·활성화 실패 표면화·vendor 방식 전달.
 *
 * 회귀(웹·터미널 공통): 연쇄 설치가 (a) 의존 확장 활성화 결과를 버려 엉뚱한 「의존 확장이 활성이 아님」으로 실패했고,
 * (b) 의존의 의존을 풀지 않아 2단계 의존 확장이 실패했으며, (c) 의존 확장은 vendor 방식이 auto 로 고정됐다.
 */
class CascadeInstallOrchestratorTest extends TestCase
{
    /** @var array<string, array<string, mixed>> 식별자 → 모듈 정보 */
    private array $modules = [];

    /** @var array<string, array<string, mixed>> 식별자 → 플러그인 정보 */
    private array $plugins = [];

    /** @var MockInterface&ModuleService 모듈 서비스 대역 */
    private $moduleService;

    /** @var MockInterface&PluginService 플러그인 서비스 대역 */
    private $pluginService;

    /**
     * 서비스 대역을 만든다 — 정보는 $modules·$plugins 배열에서 읽는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleService = Mockery::mock(ModuleService::class);
        $this->moduleService->shouldReceive('getModuleInfo')->andReturnUsing(fn (string $id) => $this->modules[$id] ?? null);
        $this->pluginService = Mockery::mock(PluginService::class);
        $this->pluginService->shouldReceive('getPluginInfo')->andReturnUsing(fn (string $id) => $this->plugins[$id] ?? null);
    }

    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 서비스 인스턴스.
     *
     * @return CascadeInstallOrchestrator 서비스
     */
    private function orchestrator(): CascadeInstallOrchestrator
    {
        return new CascadeInstallOrchestrator($this->moduleService, $this->pluginService, Mockery::mock(TemplateService::class), Mockery::mock(LanguagePackService::class));
    }

    /**
     * 미설치 확장 정보.
     *
     * @param  string  $version  번들 버전
     * @param  array<string, mixed>  $dependencies  원본형 의존 선언
     * @return array<string, mixed> 정보
     */
    private function uninstalled(string $version, array $dependencies = []): array
    {
        return ['is_installed' => false, 'status' => 'uninstalled', 'version' => $version, 'dependencies' => $dependencies];
    }

    /**
     * 의존의 의존까지 풀고, 의존 확장이 먼저 오는 순서로 돌려준다.
     *
     * @effects cascade_resolves_transitive_dependencies_in_install_order
     *
     * @return void 반환값 없음
     */
    public function test_resolves_two_level_dependencies_in_order(): void
    {
        $this->plugins['pay'] = $this->uninstalled('1.0.0', ['modules' => ['shop' => '>=1.0.0']]);
        $this->modules['shop'] = $this->uninstalled('1.3.0', ['modules' => ['base' => '^2.0.0']]);
        $this->modules['base'] = ['is_installed' => true, 'status' => ExtensionStatus::Inactive->value, 'version' => '2.1.0', 'dependencies' => []];

        $plan = $this->orchestrator()->resolveDependencyPlan('plugin', 'pay');

        $this->assertSame([], $plan['errors']);
        $this->assertSame(['base', 'shop'], array_column($plan['steps'], 'identifier'));
        $this->assertSame(['activate', 'install_activate'], array_column($plan['steps'], 'action'));
        $this->assertSame(['shop', 'pay'], array_column($plan['steps'], 'required_by'));
    }

    /**
     * 이미 활성이고 버전을 충족하는 의존은 계획에 없고, 버전 미달은 오류다.
     *
     * @effects cascade_reports_active_dependency_version_unmet
     *
     * @return void 반환값 없음
     */
    public function test_active_satisfied_is_skipped_and_version_unmet_is_error(): void
    {
        $this->plugins['pay'] = $this->uninstalled('1.0.0', ['modules' => ['shop' => '>=1.3.0', 'ok' => '*']]);
        $this->modules['shop'] = ['is_installed' => true, 'status' => ExtensionStatus::Active->value, 'version' => '1.2.0'];
        $this->modules['ok'] = ['is_installed' => true, 'status' => ExtensionStatus::Active->value, 'version' => '9.0.0'];

        $plan = $this->orchestrator()->resolveDependencyPlan('plugin', 'pay');

        $this->assertSame([], $plan['steps']);
        $this->assertCount(1, $plan['errors']);
        $this->assertStringContainsString('shop', $plan['errors'][0]);
        $this->assertStringContainsString('>=1.3.0', $plan['errors'][0]);
    }

    /**
     * 순환 의존을 감지한다.
     *
     * @effects cascade_detects_dependency_cycle
     *
     * @return void 반환값 없음
     */
    public function test_detects_cycle(): void
    {
        $this->modules['a'] = $this->uninstalled('1.0.0', ['modules' => ['b' => '*']]);
        $this->modules['b'] = $this->uninstalled('1.0.0', ['modules' => ['a' => '*']]);
        $this->plugins['p'] = $this->uninstalled('1.0.0', ['modules' => ['a' => '*']]);

        $plan = $this->orchestrator()->resolveDependencyPlan('plugin', 'p');

        $this->assertNotSame([], $plan['errors']);
        $this->assertStringContainsString('module:a', $plan['errors'][0]);
    }

    /**
     * 활성화가 실패하면 그 확장 이름과 실제 사유로 멈춘다 (결과를 버리지 않는다).
     *
     * @effects cascade_surfaces_dependency_activation_failure
     *
     * @return void 반환값 없음
     */
    public function test_activation_failure_surfaces_with_identifier_and_reason(): void
    {
        $this->modules['shop'] = $this->uninstalled('1.3.0');
        $this->moduleService->shouldReceive('installModule')->once()->andReturn(['identifier' => 'shop']);
        $this->moduleService->shouldReceive('activateModule')->once()->andReturn([
            'success' => false, 'warning' => true,
            'missing_modules' => [['identifier' => 'base', 'status' => 'not_installed']], 'missing_plugins' => [],
        ]);

        try {
            $this->orchestrator()->installDependencies([['type' => 'module', 'identifier' => 'shop']]);
            $this->fail('활성화 실패가 통과했다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('shop', $e->getMessage());
            // 사유는 사람이 읽는 문장이다 — 원문(not_installed)을 내보내지 않는다(#152 D5)
            $this->assertStringContainsString('base '.__('extensions.dependency_status.not_installed'), $e->getMessage());
            $this->assertStringNotContainsString('(not_installed)', $e->getMessage());
        }
    }

    /**
     * 의존 확장 설치에 본 확장과 같은 vendor 방식을 넘긴다.
     *
     * @effects cascade_passes_vendor_mode_to_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_passes_vendor_mode_to_dependency_install(): void
    {
        $this->modules['shop'] = $this->uninstalled('1.3.0');
        $this->moduleService->shouldReceive('installModule')->once()->with('shop', VendorMode::Bundled)->andReturn(['identifier' => 'shop']);
        $this->moduleService->shouldReceive('activateModule')->once()->andReturn(['success' => true]);

        $result = $this->orchestrator()->installDependencies([['type' => 'module', 'identifier' => 'shop']], VendorMode::Bundled);

        $this->assertSame(['installed' => ['shop'], 'activated' => ['shop'], 'skipped' => []], $result);
    }

    /**
     * 화면 선택을 계획 순서(의존 확장 먼저)로 정렬한다.
     *
     * @effects cascade_orders_selected_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_orders_selected_dependencies_by_plan(): void
    {
        $this->plugins['pay'] = $this->uninstalled('1.0.0', ['modules' => ['shop' => '*']]);
        $this->modules['shop'] = $this->uninstalled('1.0.0', ['modules' => ['base' => '*']]);
        $this->modules['base'] = $this->uninstalled('1.0.0');

        $ordered = $this->orchestrator()->orderSelectedDependencies('plugin', 'pay', [
            ['type' => 'module', 'identifier' => 'shop'],
            ['type' => 'module', 'identifier' => 'base'],
        ]);

        $this->assertSame(['base', 'shop'], array_column($ordered, 'identifier'));
    }

    /**
     * 중간 의존이 실패하면 앞서 설치·활성화해 남겨 둔 의존 확장을 오류에 함께 적는다(되돌리지 않는 현행 유지 — D14).
     *
     * @effects cascade_failure_lists_kept_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_failure_midway_lists_dependencies_already_kept(): void
    {
        $this->modules['base'] = $this->uninstalled('1.0.0');
        $this->modules['shop'] = ['is_installed' => true, 'status' => ExtensionStatus::Inactive->value, 'version' => '1.3.0'];
        $this->moduleService->shouldReceive('installModule')->once()->with('base', VendorMode::Auto)->andReturn(['identifier' => 'base']);
        $this->moduleService->shouldReceive('activateModule')->with('base')->once()->andReturn(['success' => true]);
        $this->moduleService->shouldReceive('activateModule')->with('shop')->once()->andThrow(new RuntimeException('boom'));

        try {
            $this->orchestrator()->installDependencies([['type' => 'module', 'identifier' => 'base'], ['type' => 'module', 'identifier' => 'shop']]);
            $this->fail('실패가 통과했다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('shop', $e->getMessage());
            $this->assertStringContainsString(
                __('extensions.errors.cascade_dependencies_kept', ['list' => 'base']),
                $e->getMessage(),
                '앞서 남긴 의존 확장 목록이 오류에 있다'
            );
        }

        $this->assertNull(
            CascadeInstallOrchestrator::keptDependenciesNotice(['installed' => [], 'activated' => [], 'skipped' => ['x']]),
            '남긴 것이 없으면 안내도 없다'
        );
    }
}
