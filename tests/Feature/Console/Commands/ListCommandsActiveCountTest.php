<?php

namespace Tests\Feature\Console\Commands;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\App;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `module:list`·`plugin:list`·`template:list` 요약줄의 활성 개수가 실제 상태와 맞는지 검증합니다 (#137 두 번째 증상).
 *
 * 요약은 표에 찍는 상태 문자열에 「활성」 이 들어 있는지로 셌다. 한국어에서는 「비활성」 에도 「활성」 이 들어 있어
 * 꺼진 확장까지 활성으로 셌다. 원래 상태값(active·inactive·uninstalled)으로 센다.
 */
class ListCommandsActiveCountTest extends TestCase
{
    /**
     * 한국어로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('ko');
    }

    /**
     * 활성 1, 비활성 1 인 설치 목록을 만듭니다.
     *
     * @param  array<string, string>  $extra  항목마다 덧붙일 칸
     * @return array<string, array<string, mixed>> 식별자 → 상세
     */
    private function installed(array $extra = []): array
    {
        return [
            'test-on' => ['name' => 'On', 'vendor' => 'test', 'version' => '1.0.0', 'status' => 'active'] + $extra,
            'test-off' => ['name' => 'Off', 'vendor' => 'test', 'version' => '1.0.0', 'status' => 'inactive'] + $extra,
        ];
    }

    /**
     * 모듈 목록: 비활성 1건이 활성으로 세지지 않는다.
     *
     * @scenario case=module_list
     *
     * @effects list_summary_counts_active_by_raw_status
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_module_list_counts_active_correctly(): void
    {
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('loadModules');
        $manager->shouldReceive('getInstalledModulesWithDetails')->andReturn($this->installed());
        $manager->shouldReceive('getUninstalledModules')->andReturn(['test-new' => ['name' => 'New', 'vendor' => 'test', 'version' => '1.0.0']]);
        $this->app->instance(ModuleManager::class, $manager);

        $this->artisan('module:list')
            ->expectsOutputToContain(trans_choice('modules.commands.list.summary', 3, ['total' => 3, 'installed' => 2, 'active' => 1]))
            ->assertSuccessful();
    }

    /**
     * 플러그인 목록: 비활성 1건이 활성으로 세지지 않는다.
     *
     * @scenario case=plugin_list
     *
     * @effects list_summary_counts_active_by_raw_status
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plugin_list_counts_active_correctly(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('loadPlugins');
        $manager->shouldReceive('getInstalledPluginsWithDetails')->andReturn($this->installed());
        $manager->shouldReceive('getUninstalledPlugins')->andReturn([]);
        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:list')
            ->expectsOutputToContain(trans_choice('plugins.commands.list.summary', 2, ['total' => 2, 'installed' => 2, 'active' => 1]))
            ->assertSuccessful();
    }

    /**
     * 템플릿 목록: 비활성 1건이 활성으로 세지지 않는다.
     *
     * @scenario case=template_list
     *
     * @effects list_summary_counts_active_by_raw_status
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_template_list_counts_active_correctly(): void
    {
        $manager = Mockery::mock(TemplateManager::class);
        $manager->shouldReceive('loadTemplates');
        $manager->shouldReceive('ensureLoaded');
        $manager->shouldReceive('getInstalledTemplatesWithDetails')->andReturn($this->installed(['type' => 'user']));
        $manager->shouldReceive('getUninstalledTemplates')->andReturn([]);
        $this->app->instance(TemplateManager::class, $manager);

        $this->artisan('template:list')
            ->expectsOutputToContain(trans_choice('templates.commands.list.summary', 2, ['total' => 2, 'installed' => 2, 'active' => 1]))
            ->assertSuccessful();
    }
}
