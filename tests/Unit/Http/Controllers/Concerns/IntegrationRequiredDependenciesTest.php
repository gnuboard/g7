<?php

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Enums\ExtensionStatus;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Services\Extension\CascadeInstallAuthorizer;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * 「선택 연동 함께 설치」에서 고른 연동 대상의 필수 의존을 함께 설치한다 (#153 D5).
 *
 * 종전에는 연동 대상만 설치·활성화해, 연동 대상 자신이 다른 확장을 필수로 요구하면 활성화가 실패하고 완료 안내에 실패 문구만
 * 남았다. 이제 설치 창 미리보기가 그 필수 의존을 `requires` 로 보여 주고(권한·계획 오류면 선택 불가), 설치는 관리자 연쇄 설치와
 * 같은 계획으로 의존 → 연동 순서로 진행한다. 의존 설치가 실패하면 그 연동만 사유와 함께 건너뛴다(본체 설치는 계속).
 */
class IntegrationRequiredDependenciesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $harness;

    /** @var array<int, string> 설치·활성화 호출 순서 */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> 식별자 → 확장 정보(상태) */
    private array $info = [];

    /**
     * 트레이트 하네스와 모듈·플러그인 서비스 대역을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new class
        {
            use OrchestratesCascadeInstall {
                installSelectedIntegrations as public;
            }
        };

        $calls = &$this->calls;
        $info = &$this->info;
        $installed = function (string $id) use (&$info, &$calls): array {
            $calls[] = "install:{$id}";
            $info[$id]['is_installed'] = true;

            return ['identifier' => $id];
        };
        $activated = function (string $id) use (&$info, &$calls): array {
            $calls[] = "activate:{$id}";
            $info[$id]['status'] = ExtensionStatus::Active->value;

            return ['success' => true];
        };

        $modules = Mockery::mock(ModuleService::class);
        $modules->shouldReceive('getModuleInfo')->andReturnUsing(function (string $id) use (&$info): ?array {
            return $info[$id] ?? null;
        });
        $modules->shouldReceive('installModule')->andReturnUsing(fn (string $id) => $installed($id));
        $modules->shouldReceive('activateModule')->andReturnUsing(fn (string $id) => $activated($id));
        $plugins = Mockery::mock(PluginService::class);
        $plugins->shouldReceive('getPluginInfo')->andReturnUsing(function (string $id) use (&$info): ?array {
            return $info[$id] ?? null;
        });
        $plugins->shouldReceive('installPlugin')->andReturnUsing(fn (string $id) => $installed($id));
        $plugins->shouldReceive('activatePlugin')->andReturnUsing(fn (string $id) => $activated($id));
        $this->app->instance(ModuleService::class, $modules);
        $this->app->instance(PluginService::class, $plugins);
    }

    /**
     * 미설치 확장 정보를 등록한다.
     *
     * @param  string  $identifier  식별자
     * @param  string  $name  표시 이름
     * @return void 반환값 없음
     */
    private function notInstalled(string $identifier, string $name = ''): void
    {
        $this->info[$identifier] = ['identifier' => $identifier, 'name' => $name ?: $identifier, 'is_installed' => false, 'status' => 'uninstalled'];
    }

    /**
     * 연쇄 설치 계획·의존 설치 대역을 컨테이너에 둔다 — 의존 설치는 실제 서비스 대역을 거쳐 순서를 남긴다.
     *
     * @param  array<string, array{steps: array<int, array<string, mixed>>, errors: array<int, string>}>  $plans  `type:id` → 계획
     * @param  array<int, string>  $failing  설치가 실패할 의존 식별자
     * @return MockInterface 오케스트레이터 대역
     */
    private function orchestrator(array $plans, array $failing = []): MockInterface
    {
        $orchestrator = Mockery::mock(CascadeInstallOrchestrator::class);
        $orchestrator->shouldReceive('resolveDependencyPlan')->andReturnUsing(fn (string $type, string $id) => $plans["{$type}:{$id}"] ?? ['steps' => [], 'errors' => []]);
        $orchestrator->shouldReceive('installDependencies')->andReturnUsing(function (array $steps) use ($failing): array {
            foreach ($steps as $step) {
                if (in_array($step['identifier'], $failing, true)) {
                    throw new RuntimeException("의존 확장 {$step['identifier']} 설치 실패");
                }
                // 실제 오케스트레이터처럼 이미 활성이면 건너뛴다
                if (($this->info[$step['identifier']]['status'] ?? null) === ExtensionStatus::Active->value) {
                    continue;
                }
                $service = $step['type'] === 'module' ? app(ModuleService::class) : app(PluginService::class);
                $step['type'] === 'module' ? $service->installModule($step['identifier']) : $service->installPlugin($step['identifier']);
                $step['type'] === 'module' ? $service->activateModule($step['identifier']) : $service->activatePlugin($step['identifier']);
            }

            return ['installed' => [], 'activated' => [], 'skipped' => []];
        });
        $this->app->instance(CascadeInstallOrchestrator::class, $orchestrator);

        return $orchestrator;
    }

    /**
     * 권한 판정 대역 — 기본은 모두 허용.
     *
     * @param  bool  $allowed  허용 여부
     * @return void 반환값 없음
     */
    private function authorizer(bool $allowed = true): void
    {
        $authorizer = Mockery::mock(CascadeInstallAuthorizer::class);
        $authorizer->shouldReceive('canSelectDependency')->andReturn($allowed);
        $this->app->instance(CascadeInstallAuthorizer::class, $authorizer);
    }

    /**
     * 계획 단계 한 줄.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @return array<string, mixed> 계획 단계
     */
    private static function step(string $type, string $identifier): array
    {
        return ['type' => $type, 'identifier' => $identifier, 'action' => 'install_activate', 'required_version' => '*', 'installed_version' => null, 'available_version' => '1.0.0', 'required_by' => 'x'];
    }

    /**
     * 연동 플러그인 X(daum_postcode)가 모듈 Y(page)를 필수로 요구하면 Y 를 먼저 설치·활성화한 뒤 X 를 설치·활성화한다.
     *
     * @scenario surface=integration_install, edge=module_to_plugin
     *
     * @effects integration_required_dependency_installed_before_integration
     *
     * @return void 반환값 없음
     */
    public function test_required_dependency_is_installed_before_integration(): void
    {
        $this->notInstalled('daum');
        $this->notInstalled('page');
        $this->authorizer();
        $this->orchestrator(['plugin:daum' => ['steps' => [self::step('module', 'page')], 'errors' => []]]);

        $failures = $this->harness->installSelectedIntegrations([['type' => 'plugin', 'identifier' => 'daum']]);

        $this->assertSame([], $failures);
        $this->assertSame(['install:page', 'activate:page', 'install:daum', 'activate:daum'], $this->calls);
    }

    /**
     * 사슬(연동 → 모듈 → 플러그인) 계획을 순서대로 설치하고, 두 연동이 같은 의존을 요구하면 한 번만 설치한다.
     * 연동끼리도 서로의 의존 순서로 맞춘다(연동 B 가 연동 A 를 요구하면 A 먼저).
     *
     * @scenario surface=integration_install, edge=chain
     *
     * @effects integration_required_dependency_chain_installed_once_in_order
     *
     * @return void 반환값 없음
     */
    public function test_chain_and_shared_dependency_installed_once_in_order(): void
    {
        foreach (['b', 'a', 'mid', 'leaf'] as $id) {
            $this->notInstalled($id);
        }
        $this->authorizer();
        $this->orchestrator([
            'plugin:b' => ['steps' => [self::step('plugin', 'leaf'), self::step('module', 'mid'), self::step('plugin', 'a')], 'errors' => []],
            'plugin:a' => ['steps' => [self::step('plugin', 'leaf'), self::step('module', 'mid')], 'errors' => []],
        ]);

        $failures = $this->harness->installSelectedIntegrations([['type' => 'plugin', 'identifier' => 'b'], ['type' => 'plugin', 'identifier' => 'a']]);

        $this->assertSame([], $failures);
        $this->assertSame([
            'install:leaf', 'activate:leaf', 'install:mid', 'activate:mid', 'install:a', 'activate:a', 'install:b', 'activate:b',
        ], $this->calls);
    }

    /**
     * 계획에 순환·없음 오류가 있거나 필수 의존 설치가 실패하면 그 연동만 사유와 함께 건너뛴다 — 다른 연동은 계속.
     *
     * @scenario surface=integration_install, edge=cycle
     *
     * @effects integration_skipped_with_reason_when_required_dependency_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_integration_skipped_when_required_dependency_cannot_be_installed(): void
    {
        foreach (['cyc', 'needy', 'broken', 'fine'] as $id) {
            $this->notInstalled($id);
        }
        $this->authorizer();
        $this->orchestrator([
            'plugin:cyc' => ['steps' => [], 'errors' => ['의존 관계가 순환합니다: plugin:cyc → module:x → plugin:cyc']],
            'plugin:needy' => ['steps' => [self::step('module', 'broken')], 'errors' => []],
        ], failing: ['broken']);

        $failures = $this->harness->installSelectedIntegrations([
            ['type' => 'plugin', 'identifier' => 'cyc'],
            ['type' => 'plugin', 'identifier' => 'needy'],
            ['type' => 'plugin', 'identifier' => 'fine'],
        ]);

        $this->assertSame('의존 관계가 순환합니다: plugin:cyc → module:x → plugin:cyc', $failures['plugin:cyc'] ?? null);
        $this->assertSame('의존 확장 broken 설치 실패', $failures['plugin:needy'] ?? null);
        $this->assertArrayNotHasKey('plugin:fine', $failures);
        $this->assertSame(['install:fine', 'activate:fine'], $this->calls, '건너뛴 연동은 설치하지 않고 다른 연동은 계속한다');
    }

    /**
     * 필수 의존을 설치할 권한이 없으면 요청을 직접 만들어도 그 연동을 설치하지 않는다.
     *
     * @scenario surface=integration_install, edge=module_to_plugin
     *
     * @effects integration_required_dependency_respects_permission
     *
     * @return void 반환값 없음
     */
    public function test_required_dependency_without_permission_skips_integration(): void
    {
        $this->notInstalled('daum');
        $this->notInstalled('page');
        $this->authorizer(false);
        $this->orchestrator(['plugin:daum' => ['steps' => [self::step('module', 'page')], 'errors' => []]]);

        $failures = $this->harness->installSelectedIntegrations([['type' => 'plugin', 'identifier' => 'daum']]);

        $this->assertSame(__('extensions.integrations.requires_no_permission', ['identifier' => 'page']), $failures['plugin:daum'] ?? null);
        $this->assertSame([], $this->calls);
    }

    /**
     * 설치 창 미리보기는 연동 항목에 필수 의존(`requires`)을 싣고, 계획 오류·권한 없음이면 그 연동을 고를 수 없게 한다.
     *
     * @scenario surface=integration_install, edge=module_to_plugin
     *
     * @effects install_preview_integrations_expose_requires_and_block_reason
     *
     * @return void 반환값 없음
     */
    public function test_install_preview_exposes_requires_and_blocks_unplannable_integrations(): void
    {
        $this->notInstalled('page', '페이지');
        $this->notInstalled('daum');
        $this->notInstalled('cyc');
        $orchestrator = $this->orchestrator([
            'plugin:daum' => ['steps' => [self::step('module', 'page')], 'errors' => []],
            'plugin:cyc' => ['steps' => [], 'errors' => ['순환 사유']],
        ]);
        $authorizer = Mockery::mock(CascadeInstallAuthorizer::class);
        // 연동 대상(플러그인) 설치는 허용, 필수 의존 모듈 설치는 「deny」 플러그인일 때만 막는다
        $authorizer->shouldReceive('canSelectDependency')->andReturn(true)->byDefault();

        $builder = new ExtensionInstallPreviewBuilder(
            app(ModuleService::class),
            app(PluginService::class),
            Mockery::mock(TemplateService::class),
            Mockery::mock(LanguagePackService::class),
            $authorizer,
            $orchestrator,
        );
        $row = fn (string $id, string $status = 'not_installed') => ['type' => 'plugin', 'identifier' => $id, 'name' => $id, 'is_installed' => $status !== 'not_installed', 'is_active' => $status === 'active', 'is_met' => $status === 'active'];
        $build = new \ReflectionMethod($builder, 'buildIntegrations');
        $items = collect($build->invoke($builder, ['integration_constraints' => [$row('daum'), $row('cyc'), $row('done', 'active'), $row('ghost')]]))->keyBy('identifier');

        $this->assertSame([['type' => 'module', 'identifier' => 'page', 'name' => '페이지', 'action' => 'install_activate']], $items['daum']['requires']);
        $this->assertTrue($items['daum']['can_select']);
        $this->assertTrue($items['daum']['default_selected']);

        $this->assertSame('순환 사유', $items['cyc']['blocked_reason']);
        $this->assertFalse($items['cyc']['can_select']);
        $this->assertFalse($items['cyc']['default_selected']);

        // 연동 대상 자신의 정보를 읽지 못하면(ghost) 계획을 세우지 않고 종전처럼 고를 수 있다 — 대상 없음은 설치 단계가 알린다
        $this->assertSame([], $items['ghost']['requires']);
        $this->assertNull($items['ghost']['blocked_reason']);
        $this->assertTrue($items['ghost']['can_select']);
        $orchestrator->shouldNotHaveReceived('resolveDependencyPlan', ['plugin', 'ghost']);

        // 이미 활성인 연동은 계획을 세우지 않는다
        $this->assertSame([], $items['done']['requires']);
        $orchestrator->shouldNotHaveReceived('resolveDependencyPlan', ['plugin', 'done']);

        // 필수 의존을 설치할 권한이 없으면 고를 수 없다
        $denied = Mockery::mock(CascadeInstallAuthorizer::class);
        $denied->shouldReceive('canSelectDependency')->andReturnUsing(fn (string $type) => $type === 'plugin');
        $deniedBuilder = new ExtensionInstallPreviewBuilder(app(ModuleService::class), app(PluginService::class), Mockery::mock(TemplateService::class), Mockery::mock(LanguagePackService::class), $denied, $orchestrator);
        $deniedItem = $build->invoke($deniedBuilder, ['integration_constraints' => [$row('daum')]])[0];
        $this->assertTrue($deniedItem['blocked_by_permission']);
        $this->assertFalse($deniedItem['can_select']);
    }
}
