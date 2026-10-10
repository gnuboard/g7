<?php

namespace Tests\Feature\Analytics;

use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\StatsDimension;
use App\Extension\HookManager;
use App\Listeners\Analytics\PurgeExtensionDataListener;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\ExtensionPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확장 제거 시 통계 정리 — 전환·차원 삭제, 실행 이력, 보존 선택 존중.
 *
 * 확장이 사라진 뒤에도 그 확장의 목표·차원이 리포트에 남으면 운영자는 어디서 온
 * 수치인지 알 수 없다. 반대로 운영자가 데이터 보존을 선택했는데 지워지면 되돌릴 수
 * 없는 손실이다. 두 방향 모두 조용히 어긋나므로 행 수로 직접 단언한다.
 */
class ExtensionPurgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 정리 대상과 남아야 할 데이터를 만듭니다.
     *
     * @return void 없음
     */
    private function seedLedger(): void
    {
        AnalyticsConversion::factory()->create([
            'goal_key' => 'sirsoft-ecommerce.order_completed',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);
        AnalyticsConversion::factory()->create([
            'goal_key' => 'core.user_registered',
            'extension_identifier' => null,
        ]);

        AnalyticsDimensionStat::factory()->create(['dimension' => 'ext:sirsoft-ecommerce:product_id']);
        AnalyticsDimensionStat::factory()->create(['dimension' => 'ext:sirsoft-board:board_id']);
        AnalyticsDimensionStat::factory()->create(['dimension' => 'page_url']);
    }

    /**
     * 확장의 전환과 차원만 지우고 다른 확장·코어 것은 남긴다.
     *
     * @scenario extension=module, delete_data=true
     *
     * @effects extension_purge_removes_only_that_extensions_rows
     *
     * @return void 없음
     */
    public function test_purge_removes_only_that_extensions_rows(): void
    {
        $this->seedLedger();

        $counts = app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        $this->assertSame(['conversions' => 1, 'dimensions' => 1, 'failed' => false], $counts);
        $this->assertSame(0, AnalyticsConversion::where('extension_identifier', 'sirsoft-ecommerce')->count());
        // 코어 전환과 다른 확장의 차원은 남아야 한다.
        $this->assertSame(1, AnalyticsConversion::count());
        $this->assertSame(2, AnalyticsDimensionStat::count());
    }

    /**
     * 확장 목표의 전환 집계 행도 함께 지운다 — 전환 탭에 그 확장 목표가 수치·매출과 함께 남지 않는다.
     *
     * 전환 집계는 `goal` 차원에 목표 키를 라벨로 쌓는다. 행을 손으로 만들지 않고 실제 집계로
     * 만들어, 라벨 형식이 바뀌어도 이 가드가 같은 행을 재게 한다.
     *
     * @scenario extension=module, delete_data=true
     *
     * @effects extension_purge_removes_that_extensions_goal_rows
     *
     * @return void 없음
     */
    public function test_purge_removes_that_extensions_goal_rows(): void
    {
        $date = '2026-09-01';
        $visit = AnalyticsVisit::factory()->onDate($date)->create();

        AnalyticsConversion::factory()->forVisit($visit)->forExtension('sirsoft-ecommerce', 'order_completed')->create(['revenue' => 45000]);
        AnalyticsConversion::factory()->forVisit($visit)->forExtension('sirsoft-board', 'post_written')->create();
        AnalyticsConversion::factory()->forVisit($visit)->create(['goal_key' => 'core.user_registered', 'extension_identifier' => null]);

        app(ArchiveService::class)->archiveDay($date);

        $goalLabels = fn (): array => AnalyticsDimensionStat::query()
            ->where('dimension', StatsDimension::Goal->value)->distinct()->orderBy('label')->pluck('label')->all();

        $this->assertSame(['core.user_registered', 'sirsoft-board.post_written', 'sirsoft-ecommerce.order_completed'], $goalLabels(), '집계가 목표 행을 만들어야 가드가 잰다');

        app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        $this->assertSame(['core.user_registered', 'sirsoft-board.post_written'], $goalLabels(), '그 확장 목표 행만 사라진다');
    }

    /**
     * 정리가 실행 이력에 남는다.
     *
     * 이 삭제는 운영자 눈에 보이지 않는 곳에서 일어난다. 「어제까지 있던 수치가 왜
     * 사라졌는지」를 진단 화면에서 찾을 수 있어야 한다.
     *
     * @scenario extension=module, delete_data=true
     *
     * @effects extension_purge_is_recorded_in_the_run_history
     *
     * @return void 없음
     */
    public function test_purge_records_an_archive_run(): void
    {
        $this->seedLedger();

        app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        $run = AnalyticsArchiveRun::where('run_type', ArchiveRunType::ExtensionPurge->value)->first();

        $this->assertNotNull($run);
        $this->assertSame(ArchiveRunStatus::Success, $run->status);
        $this->assertSame(100, (int) $run->progress_percent);
        $this->assertNotNull($run->finished_at);
    }

    /**
     * 정리가 끝나면 확장 제거 훅을 쏜다.
     *
     * 활동 로그 리스너가 이 훅을 구독하고 있다 — 쏘지 않으면 관리자 활동 로그에
     * 확장 데이터 삭제가 한 줄도 남지 않는다.
     *
     * @scenario extension=plugin, delete_data=true
     *
     * @effects extension_purged_hook_fires_with_counts
     *
     * @return void 없음
     */
    public function test_purge_fires_extension_purged_hook(): void
    {
        $this->seedLedger();

        $received = null;
        HookManager::addAction('core.analytics.extension_purged', function ($payload) use (&$received): void {
            $received = $payload;
        });

        app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        $this->assertIsArray($received);
        $this->assertSame('sirsoft-ecommerce', $received['extension_identifier']);
        $this->assertSame('module', $received['extension_type']);
        $this->assertSame(1, $received['counts']['conversions']);
    }

    /**
     * 데이터 보존을 선택하면 아무것도 지우지 않는다.
     *
     * 모듈 훅의 두 번째 인자는 정보 배열이라 truthy 다. 한 메서드로 두 훅을 받으면
     * 이 자리를 `$deleteData` 로 오해해 보존을 선택한 모듈의 데이터를 지운다.
     *
     * @scenario extension=module, delete_data=false
     *
     * @effects preserved_extension_data_survives_uninstall
     *
     * @return void 없음
     */
    public function test_module_uninstall_preserving_data_purges_nothing(): void
    {
        $this->seedLedger();

        $listener = app(PurgeExtensionDataListener::class);
        $listener->handleModule('sirsoft-ecommerce', ['name' => 'ecommerce'], false);

        $this->assertSame(2, AnalyticsConversion::count());
        $this->assertSame(3, AnalyticsDimensionStat::count());
        $this->assertSame(0, AnalyticsArchiveRun::count());
    }

    /**
     * 모듈·플러그인 훅의 인자 순서를 각각 바르게 읽는다.
     *
     * `core.modules.after_uninstall($name, $info, $deleteData)` 와
     * `core.plugins.after_uninstall($name, $deleteData, $result)` 는 순서가 다르다.
     *
     * @scenario extension=module+plugin, delete_data=true
     *
     * @effects uninstall_hook_argument_order_is_read_per_extension_type
     *
     * @return void 없음
     */
    public function test_listener_reads_each_hook_argument_order(): void
    {
        $this->seedLedger();

        $listener = app(PurgeExtensionDataListener::class);

        $listener->handleModule('sirsoft-ecommerce', ['name' => 'ecommerce'], true);
        $this->assertSame(0, AnalyticsConversion::where('extension_identifier', 'sirsoft-ecommerce')->count());

        $listener->handlePlugin('sirsoft-board', true, ['success' => true]);
        $this->assertSame(0, AnalyticsDimensionStat::where('dimension', 'like', 'ext:sirsoft-board:%')->count());

        // 코어 전환과 코어 차원은 두 번의 정리에도 살아남아야 한다.
        $this->assertSame(1, AnalyticsConversion::count());
        $this->assertSame(1, AnalyticsDimensionStat::count());
    }

    /**
     * 차원 삭제가 실패하면 전환 삭제도 되돌리고, 실패를 훅으로 알린다.
     *
     * 두 삭제가 원자적이지 않으면 전환만 사라지고 차원은 남는다 — 그런데 실행 이력은
     * 실패로 적혀 있어, 운영자는 「아무것도 안 지워졌다」고 읽고 사라진 전환 수치의
     * 원인을 영영 찾지 못한다. 활동 로그는 이 훅만 구독하므로 실패에 침묵하면
     * 「지워진 줄 알았는데 남아 있다」를 알 길이 없다.
     *
     * @scenario extension=module, delete_data=true, outcome=failure
     *
     * @effects extension_purge_rolls_back_when_a_target_fails
     *
     * @return void 없음
     */
    public function test_failed_purge_rolls_back_and_reports_the_failure(): void
    {
        $this->seedLedger();

        // 차원 삭제만 터뜨린다 — 전환은 이미 지워진 뒤에 도달하는 자리다.
        $stats = \Mockery::mock(StatsRepositoryInterface::class);
        $stats->shouldReceive('deleteDimensionsForExtension')
            ->andThrow(new \RuntimeException('락 타임아웃'));
        $this->app->instance(StatsRepositoryInterface::class, $stats);

        $received = null;
        HookManager::addAction('core.analytics.extension_purged', function ($payload) use (&$received): void {
            $received = $payload;
        });

        $counts = app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        // 전환 삭제가 되돌려져 두 건 모두 남아 있어야 한다.
        $this->assertSame(2, AnalyticsConversion::count());
        $this->assertSame(['conversions' => 0, 'dimensions' => 0, 'failed' => true], $counts);

        // 실패가 훅으로 드러나야 활동 로그에 한 줄이라도 남는다.
        $this->assertIsArray($received);
        $this->assertTrue($received['failed']);

        $run = AnalyticsArchiveRun::where('run_type', ArchiveRunType::ExtensionPurge->value)->first();
        $this->assertSame(ArchiveRunStatus::Failed, $run->status);
    }

    /**
     * 플러그인을 「데이터 보존」으로 제거하면 아무것도 지우지 않는다(플러그인 훅 인자 순서로).
     *
     * @scenario extension=plugin, delete_data=false
     *
     * @effects preserved_extension_data_survives_uninstall
     *
     * @return void 없음
     */
    public function test_plugin_uninstall_preserving_data_purges_nothing(): void
    {
        $this->seedLedger();

        app(PurgeExtensionDataListener::class)->handlePlugin('sirsoft-board', false, ['success' => true]);

        $this->assertSame(1, AnalyticsDimensionStat::where('dimension', 'like', 'ext:sirsoft-board:%')->count());
        $this->assertSame(0, AnalyticsArchiveRun::count());
    }
}
