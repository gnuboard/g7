<?php

namespace Tests\Feature\Console\Commands\Template;

use App\Extension\TemplateManager;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * template:deactivate 는 「유형의 마지막 활성 템플릿」을 기본으로 끄지 않고 `--force` 로만 끈다 (설계 확정 2026-11-08)
 *
 * 종전에는 그 거절이 관리자 화면 경로에만 있어, CLI 로 유일한 활성 관리자·사용자 템플릿을 끄면 그 화면 전체가 그려지지
 * 않았다(끈 뒤 「활성 템플릿 없음」 경고만 냈다). 판정은 `TemplateLastActiveGuard` 한 곳이다. `--force` 는 복구 작업 등
 * 의도한 경우를 위해 종전 동작(끄고 경고)을 남긴다.
 */
class DeactivateTemplateLastActiveGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> 비활성화 호출 기록 */
    private array $calls = [];

    /**
     * 비활성화 호출을 기록하는 가짜 템플릿 관리자를 묶고 기존 템플릿을 모두 끕니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = Mockery::mock(TemplateManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('loadTemplates')->andReturnNull();
        $manager->shouldReceive('deactivateTemplate')->andReturnUsing(function (string $id) {
            $this->calls[] = $id;

            return true;
        });
        $this->app->instance(TemplateManager::class, $manager);

        Template::query()->update(['status' => 'inactive']);
    }

    /**
     * 유형의 유일한 활성 템플릿은 기본으로 끄지 않고 사유와 `--force` 안내를 남긴 채 실패로 끝난다.
     *
     * @scenario case=last_active
     *
     * @effects cli_deactivate_refuses_last_active_template_without_force
     *
     * @return void 반환값 없음
     */
    public function test_last_active_template_is_refused_without_force(): void
    {
        Template::factory()->create(['identifier' => 'acme-admin', 'type' => 'admin', 'status' => 'active']);

        $this->artisan('template:deactivate', ['identifier' => 'acme-admin'])
            ->expectsOutput('❌ '.__('templates.errors.last_active_deactivate', ['type' => __('templates.types.admin')]))
            ->expectsOutput(__('templates.commands.deactivate.last_active_force_hint', ['template' => 'acme-admin']))
            ->assertExitCode(1);

        $this->assertSame([], $this->calls, '마지막 활성 템플릿을 --force 없이 껐다');
    }

    /**
     * `--force` 를 주면 마지막 활성 템플릿도 끄고 「활성 템플릿 없음」 경고를 낸다(종전 동작).
     *
     * @scenario case=last_active_force
     *
     * @effects cli_deactivate_refuses_last_active_template_without_force
     *
     * @return void 반환값 없음
     */
    public function test_force_deactivates_last_active_template_with_warning(): void
    {
        Template::factory()->create(['identifier' => 'acme-admin', 'type' => 'admin', 'status' => 'active']);

        $this->artisan('template:deactivate', ['identifier' => 'acme-admin', '--force' => true])
            ->expectsOutput('⚠️  '.__('templates.commands.deactivate.no_active_warning', ['type' => __('templates.types.admin')]))
            ->assertExitCode(0);

        $this->assertSame(['acme-admin'], $this->calls);
    }

    /**
     * 같은 유형에 다른 활성 템플릿이 있으면 `--force` 없이도 끈다.
     *
     * @scenario case=other_active
     *
     * @effects cli_deactivate_refuses_last_active_template_without_force
     *
     * @return void 반환값 없음
     */
    public function test_template_with_another_active_is_deactivated(): void
    {
        Template::factory()->create(['identifier' => 'acme-admin', 'type' => 'admin', 'status' => 'active']);
        Template::factory()->create(['identifier' => 'acme-admin-2', 'type' => 'admin', 'status' => 'active']);

        $this->artisan('template:deactivate', ['identifier' => 'acme-admin'])->assertExitCode(0);

        $this->assertSame(['acme-admin'], $this->calls);
    }
}
