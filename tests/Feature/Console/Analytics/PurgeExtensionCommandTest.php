<?php

namespace Tests\Feature\Console\Analytics;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\ExtensionStatus;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsDimensionStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 확장 통계 정리 커맨드 — 제거한 확장의 통계를 나중에 지우거나, 실패한 정리를 다시 돌린다.
 *
 * 확장을 「데이터 보존」으로 제거하면 그 확장의 전환·차원 집계가 남는다. 나중에 지우려는 운영자,
 * 그리고 제거 때 정리가 실패(실행 이력 「실패」)한 뒤 다시 돌리려는 운영자에게 이 커맨드가 유일한
 * 수단이다. 되돌릴 수 없으므로 전체 삭제와 같은 두 겹 확인을 거친다.
 */
class PurgeExtensionCommandTest extends TestCase
{
    use RefreshDatabase;

    /** 대화형 확인 문구 */
    private const PROMPT = 'module sirsoft-ecommerce 의 방문자 통계 전환·차원 집계를 지웁니다. 되돌릴 수 없습니다. 진행할까요? (yes/no) [no]';

    /**
     * 정리 대상과 남아야 할 데이터를 만듭니다.
     *
     * @return void 없음
     */
    private function seedRows(): void
    {
        AnalyticsConversion::factory()->create([
            'goal_key' => 'sirsoft-ecommerce.order_completed',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);
        AnalyticsConversion::factory()->create(['goal_key' => 'core.user_registered']);
        AnalyticsDimensionStat::factory()->create(['dimension' => 'ext:sirsoft-ecommerce:product_id']);
        AnalyticsDimensionStat::factory()->create(['dimension' => 'page_url']);
    }

    /**
     * 확인 옵션이 없거나 종류가 틀리면 아무것도 지우지 않는다.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting, extension=module, delete_data=true
     *
     * @effects extension_purge_command_requires_confirmation
     */
    public function test_it_refuses_without_confirmation_or_with_a_wrong_type(): void
    {
        $this->seedRows();

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce'])->assertFailed();
        $this->artisan('analytics:purge-extension', ['type' => 'theme', 'identifier' => 'x', '--confirm' => true])->assertFailed();
        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', '--confirm' => true])
            ->expectsQuestion(self::PROMPT, 'no')
            ->assertSuccessful();

        $this->assertSame(2, AnalyticsConversion::count());
        $this->assertSame(2, AnalyticsDimensionStat::count());
        $this->assertSame(0, AnalyticsArchiveRun::count(), '거절한 실행은 이력도 남기지 않는다');
    }

    /**
     * 확인을 통과하면 그 확장의 행만 지우고 실행 이력을 남긴다.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting, extension=module, delete_data=true
     *
     * @effects extension_purge_command_removes_only_that_extensions_rows
     */
    public function test_confirmed_run_removes_only_that_extensions_rows(): void
    {
        $this->seedRows();

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', '--confirm' => true])
            ->expectsQuestion(self::PROMPT, 'yes')
            ->assertSuccessful();

        $this->assertSame(0, AnalyticsConversion::where('extension_identifier', 'sirsoft-ecommerce')->count());
        $this->assertSame(1, AnalyticsConversion::count());
        $this->assertSame(1, AnalyticsDimensionStat::count());

        $run = AnalyticsArchiveRun::where('run_type', ArchiveRunType::ExtensionPurge->value)->sole();
        $this->assertSame(ArchiveRunStatus::Success, $run->status);
    }

    /**
     * 켜져 있는 확장은 --force 없이 지우지 않는다.
     *
     * 이 커맨드의 용도는 제거하거나 끈 확장의 뒷정리다. 켜진 확장의 전환을 지우면 그 확장이
     * 계속 쌓는 수치와 어긋나고 「방문자당 한 번」 목표의 중복 판정 근거도 사라진다.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting, extension=module, delete_data=true
     *
     * @effects extension_purge_command_refuses_an_active_extension
     */
    public function test_an_active_extension_needs_force(): void
    {
        $this->seedRows();
        $modules = Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        $modules->shouldReceive('getModuleInfo')->with('sirsoft-ecommerce')
            ->andReturn(['is_installed' => true, 'status' => ExtensionStatus::Active->value]);
        $this->app->instance(ModuleManagerInterface::class, $modules);

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', '--confirm' => true])
            ->expectsOutputToContain('켜져 있습니다')
            ->assertFailed();
        $this->assertSame(2, AnalyticsConversion::count(), '켜진 확장은 지우지 않는다');
        $this->assertSame(0, AnalyticsArchiveRun::count());

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', '--confirm' => true, '--force' => true])
            ->expectsQuestion(self::PROMPT, 'yes')
            ->assertSuccessful();
        $this->assertSame(0, AnalyticsConversion::where('extension_identifier', 'sirsoft-ecommerce')->count(), '--force 면 지운다');
    }

    /**
     * 설치되지 않은 식별자는 확인 전에 알린다 — 오타가 「0건 삭제 성공」으로 끝나지 않게.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting, extension=module, delete_data=true
     *
     * @effects extension_purge_command_warns_about_an_unknown_identifier
     */
    public function test_an_identifier_that_is_not_installed_is_warned_before_confirmation(): void
    {
        $this->seedRows();
        $modules = Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        $modules->shouldReceive('getModuleInfo')->andReturn(null);
        $this->app->instance(ModuleManagerInterface::class, $modules);

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecomerce', '--confirm' => true])
            ->expectsOutputToContain('설치되어 있지 않습니다')
            ->expectsQuestion('module sirsoft-ecomerce 의 방문자 통계 전환·차원 집계를 지웁니다. 되돌릴 수 없습니다. 진행할까요? (yes/no) [no]', 'no')
            ->assertSuccessful();
        $this->assertSame(2, AnalyticsConversion::count());
    }

    /**
     * 정리가 실패하면 종료 코드가 실패다 — 「0건 삭제」로 성공처럼 끝나지 않는다.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting, extension=module, delete_data=true, outcome=failure
     *
     * @effects extension_purge_command_reports_failure
     */
    public function test_a_failed_purge_exits_with_failure(): void
    {
        $this->seedRows();

        $stats = Mockery::mock(StatsRepositoryInterface::class);
        $stats->shouldReceive('deleteDimensionsForExtension')->andThrow(new \RuntimeException('락 타임아웃'));
        $this->app->instance(StatsRepositoryInterface::class, $stats);

        $this->artisan('analytics:purge-extension', ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', '--confirm' => true])
            ->expectsQuestion(self::PROMPT, 'yes')
            ->assertFailed();

        $this->assertSame(2, AnalyticsConversion::count(), '실패한 정리는 트랜잭션이 되돌린다');
    }
}
