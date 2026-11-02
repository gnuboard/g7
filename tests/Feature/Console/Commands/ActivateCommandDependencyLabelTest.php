<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * template|module|plugin:activate 경고의 의존 상태 라벨 — 버전 미달을 「비활성」으로 출력하지 않는다 (#152)
 *
 * 회귀: 세 명령의 라벨 분기가 `not_installed` 가 아니면 모두 「비활성」이라, 켜져 있지만 요구 버전에 못 미치는 의존도
 * 「비활성」으로 보였다. 판정 자체는 TemplateActivationDependencyVersionTest·ExtensionDependencyVersionCheckTest 가 잠그고,
 * 여기서는 매니저 결과를 고정해 출력만 잠근다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class ActivateCommandDependencyLabelTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 매니저가 돌려줄 의존성 미충족 경고 결과.
     *
     * @param  string  $type  템플릿 타입 (템플릿 명령만)
     * @return array<string, mixed> 활성화 결과
     */
    private function warningResult(string $type = 'user'): array
    {
        return [
            'success' => false,
            'warning' => true,
            'message' => __('templates.warnings.missing_dependencies'),
            'missing_modules' => [
                ['identifier' => 'test-dep152-mod', 'name' => '의존 모듈', 'type' => 'module', 'status' => 'version_mismatch', 'required_version' => '>=9.9.9', 'installed_version' => '0.1.0'],
                ['identifier' => 'test-dep152-off', 'name' => '꺼진 모듈', 'type' => 'module', 'status' => 'inactive', 'required_version' => '*', 'installed_version' => '1.0.0'],
            ],
            'missing_plugins' => [
                ['identifier' => 'test-dep152-none', 'name' => 'test-dep152-none', 'type' => 'plugin', 'status' => 'not_installed', 'required_version' => '*', 'installed_version' => null],
            ],
            'template_type' => $type,
            'current_active_identifier' => 'sirsoft-admin_basic',
        ];
    }

    /**
     * template:activate 가 세 사유를 각각의 라벨로 출력하고 사용자 503 안내 한 줄을 더한다.
     *
     * @scenario surface=cli_template
     *
     * @effects cli_labels_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_template_activate_labels_each_reason(): void
    {
        app()->setLocale('ko');
        Template::factory()->create(['identifier' => 'test-dep152-tpl', 'vendor' => 'test', 'type' => 'user', 'status' => ExtensionStatus::Inactive->value]);
        $this->mock(TemplateManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadTemplates')->once();
            $mock->shouldReceive('ensureLoaded')->andReturnNull();
            $mock->shouldReceive('activateTemplate')->once()->andReturn($this->warningResult());
        });

        $this->artisan('template:activate', ['identifier' => 'test-dep152-tpl'])
            ->expectsOutputToContain('test-dep152-mod (의존 모듈) [버전 미달 0.1.0 → >=9.9.9]')
            ->expectsOutputToContain('test-dep152-off (꺼진 모듈) [비활성]')
            ->expectsOutputToContain('test-dep152-none (test-dep152-none) [미설치]')
            ->expectsOutputToContain(__('templates.commands.activate.force_503_hint_user'))
            ->assertExitCode(1);
    }

    /**
     * 관리자 템플릿이면 관리자 503 안내와 복구 명령을 출력한다.
     *
     * @scenario surface=cli_template_admin
     *
     * @effects cli_admin_template_recovery_hint
     *
     * @return void 반환값 없음
     */
    public function test_admin_template_activate_prints_recovery_command(): void
    {
        app()->setLocale('ko');
        Template::factory()->create(['identifier' => 'test-dep152-adm', 'vendor' => 'test', 'type' => 'admin', 'status' => ExtensionStatus::Inactive->value]);
        $this->mock(TemplateManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadTemplates')->once();
            $mock->shouldReceive('ensureLoaded')->andReturnNull();
            $mock->shouldReceive('activateTemplate')->once()->andReturn($this->warningResult('admin'));
        });

        $this->artisan('template:activate', ['identifier' => 'test-dep152-adm'])
            ->expectsOutputToContain('php artisan template:activate sirsoft-admin_basic')
            ->assertExitCode(1);
    }

    /**
     * module:activate · plugin:activate 도 버전 미달을 「버전 미달 설치 → 요구」로 출력한다.
     *
     * @scenario surface=cli_module_plugin
     *
     * @effects cli_labels_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_module_and_plugin_activate_label_version_mismatch(): void
    {
        app()->setLocale('ko');
        Module::create([
            'identifier' => 'test-dep152-host', 'vendor' => 'test', 'name' => ['ko' => '호스트', 'en' => 'Host'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'test-dep152-hostp', 'vendor' => 'test', 'name' => ['ko' => '호스트', 'en' => 'Host'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        $this->mock(ModuleManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadModules')->once();
            $mock->shouldReceive('activateModule')->once()->andReturn($this->warningResult());
        });
        $this->mock(PluginManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadPlugins')->once();
            $mock->shouldReceive('activatePlugin')->once()->andReturn($this->warningResult());
        });

        $this->artisan('module:activate', ['identifier' => 'test-dep152-host'])
            ->expectsOutputToContain('test-dep152-mod (의존 모듈) [버전 미달 0.1.0 → >=9.9.9]')
            ->expectsOutputToContain('[비활성]')
            ->assertExitCode(1);

        $this->artisan('plugin:activate', ['identifier' => 'test-dep152-hostp'])
            ->expectsOutputToContain('test-dep152-mod (의존 모듈) [버전 미달 0.1.0 → >=9.9.9]')
            ->expectsOutputToContain('[미설치]')
            ->assertExitCode(1);
    }
}
