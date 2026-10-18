<?php

namespace Tests\Feature\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Models\Module;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * module:deactivate — 의존하는 활성 확장 안내.
 *
 * 7.1.0 전수 실측 M3: Hello 플러그인(Hello 모듈에 의존)을 켜 둔 채 `module:deactivate gnuboard7-hello_module`
 * 을 실행하면 거절(종료 코드 1)은 됐지만 「의존하는 템플릿 목록:」 제목만 나오고 목록이 비어, 무엇 때문에
 * 거절됐는지 알 수 없었다. 모듈 관리자는 의존하는 템플릿·모듈·플러그인을 모두 돌려주는데 명령은 템플릿만
 * 출력했다. `plugin:deactivate` 와 같이 세 종류를 모두 보여 준다.
 *
 * @scenario-manifest tests/scenarios/module-deactivate-dependents-output.yaml
 */
class DeactivateModuleDependentsOutputTest extends TestCase
{
    /**
     * 모듈 관리자가 경고를 돌려주도록 맞추고 비활성화 명령을 실행할 준비를 합니다.
     *
     * @param  array<string, array<int, array{identifier: string, name: string}>>  $dependents  종류별 의존 확장
     * @return void 반환값 없음
     */
    private function prepareWarning(array $dependents): void
    {
        $record = new Module;
        $record->forceFill(['identifier' => 'qa-parent_module', 'status' => ExtensionStatus::Active->value]);

        $this->mock(ModuleRepositoryInterface::class, function (MockInterface $mock) use ($record) {
            $mock->shouldReceive('findByIdentifier')->with('qa-parent_module')->andReturn($record);
        });

        $this->mock(ModuleManager::class, function (MockInterface $mock) use ($dependents) {
            $mock->shouldReceive('loadModules')->andReturnNull();
            // 운영자 명령은 수동 비활성화 사유를 명시해 넘긴다 — 시스템 자동 비활성화와 DB 상 구분된다.
            $mock->shouldReceive('deactivateModule')->with('qa-parent_module', false, DeactivationReason::Manual->value)->andReturn(array_merge([
                'success' => false,
                'warning' => true,
                'layouts_deleted' => 0,
                'message' => '이 모듈에 의존하는 활성화된 확장이 있습니다.',
                'dependent_templates' => [],
                'dependent_modules' => [],
                'dependent_plugins' => [],
            ], $dependents));
        });
    }

    /**
     * 의존하는 플러그인만 있으면 플러그인 목록을 보여 주고 빈 템플릿 목록 제목은 내지 않는다.
     *
     * @scenario dependent=plugin
     *
     * @effects lists_dependent_plugins_and_modules
     *
     * @return void 반환값 없음
     */
    public function test_lists_dependent_plugin_when_refusing(): void
    {
        $this->prepareWarning([
            'dependent_plugins' => [['identifier' => 'qa-child_plugin', 'name' => 'QA 자식 플러그인']],
        ]);

        $this->artisan('module:deactivate', ['identifier' => 'qa-parent_module'])
            ->expectsOutputToContain('qa-child_plugin (QA 자식 플러그인)')
            ->doesntExpectOutput('의존하는 템플릿 목록:')
            ->assertExitCode(1);
    }

    /**
     * 의존하는 모듈도 보여 준다.
     *
     * @scenario dependent=module
     *
     * @effects lists_dependent_plugins_and_modules
     *
     * @return void 반환값 없음
     */
    public function test_lists_dependent_module_when_refusing(): void
    {
        $this->prepareWarning([
            'dependent_modules' => [['identifier' => 'qa-child_module', 'name' => 'QA 자식 모듈']],
        ]);

        $this->artisan('module:deactivate', ['identifier' => 'qa-parent_module'])
            ->expectsOutputToContain('qa-child_module (QA 자식 모듈)')
            ->assertExitCode(1);
    }

    /**
     * 의존하는 템플릿은 종전처럼 보여 준다.
     *
     * @scenario dependent=template
     *
     * @effects lists_dependent_templates_as_before
     *
     * @return void 반환값 없음
     */
    public function test_lists_dependent_template_when_refusing(): void
    {
        $this->prepareWarning([
            'dependent_templates' => [['identifier' => 'qa-child_template', 'name' => 'QA 자식 템플릿']],
        ]);

        $this->artisan('module:deactivate', ['identifier' => 'qa-parent_module'])
            ->expectsOutput('의존하는 템플릿 목록:')
            ->expectsOutputToContain('qa-child_template (QA 자식 템플릿)')
            ->assertExitCode(1);
    }
}
