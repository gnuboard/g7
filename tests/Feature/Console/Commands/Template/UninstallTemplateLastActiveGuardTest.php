<?php

namespace Tests\Feature\Console\Commands\Template;

use App\Extension\TemplateManager;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * template:uninstall 도 「유형의 마지막 활성 템플릿」을 지우지 않는다
 *
 * 종전에는 그 거절이 관리자 화면 경로(`TemplateService::uninstallTemplate`)에만 있어, CLI 로는 유일한 활성 관리자·사용자
 * 템플릿을 지울 수 있었다 — 지우면 그 화면 전체가 그려지지 않는다. 판정은 `TemplateLastActiveGuard` 한 곳이고, 서비스와
 * 명령이 같은 판정을 쓴다. `--force` 는 확인 질문만 건너뛸 뿐 이 거절을 건너뛰지 않는다.
 */
class UninstallTemplateLastActiveGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> 제거 호출 기록 */
    private array $calls = [];

    /**
     * 제거 호출을 기록하는 가짜 템플릿 관리자를 묶고 같은 유형의 다른 활성 템플릿을 끕니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = Mockery::mock(TemplateManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('loadTemplates')->andReturnNull();
        $manager->shouldReceive('uninstallTemplate')->andReturnUsing(function (string $id) {
            $this->calls[] = $id;

            return true;
        });
        $this->app->instance(TemplateManager::class, $manager);

        Template::query()->update(['status' => 'inactive']);
    }

    /**
     * 유형의 유일한 활성 템플릿은 --force 로도 지우지 않고 실패로 끝난다.
     *
     * @scenario case=last_active
     *
     * @effects cli_uninstall_refuses_last_active_template
     *
     * @return void 반환값 없음
     */
    public function test_last_active_template_is_not_uninstalled_even_with_force(): void
    {
        Template::factory()->create(['identifier' => 'acme-admin', 'type' => 'admin', 'status' => 'active']);

        $this->artisan('template:uninstall', ['identifier' => 'acme-admin', '--force' => true])
            ->expectsOutput('❌ '.__('templates.errors.last_active_uninstall', ['type' => __('templates.types.admin')]))
            ->assertExitCode(1);

        $this->assertSame([], $this->calls, '마지막 활성 템플릿에 제거를 불렀다');
    }

    /**
     * 같은 유형에 다른 활성 템플릿이 있거나 꺼진 템플릿이면 종전대로 지운다.
     *
     * @scenario case=other_active_or_inactive
     *
     * @effects cli_uninstall_refuses_last_active_template
     *
     * @return void 반환값 없음
     */
    public function test_template_with_another_active_or_inactive_is_uninstalled(): void
    {
        Template::factory()->create(['identifier' => 'acme-admin', 'type' => 'admin', 'status' => 'active']);
        Template::factory()->create(['identifier' => 'acme-admin-2', 'type' => 'admin', 'status' => 'active']);
        Template::factory()->create(['identifier' => 'acme-user-off', 'type' => 'user', 'status' => 'inactive']);

        $this->artisan('template:uninstall', ['identifier' => 'acme-admin', '--force' => true])->assertExitCode(0);
        $this->artisan('template:uninstall', ['identifier' => 'acme-user-off', '--force' => true])->assertExitCode(0);

        $this->assertSame(['acme-admin', 'acme-user-off'], $this->calls);
    }
}
