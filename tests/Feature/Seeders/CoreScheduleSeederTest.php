<?php

namespace Tests\Feature\Seeders;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleHistoryMode;
use App\Extension\HookManager;
use App\Jobs\GenerateSitemapJob;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\CoreUpdateService;
use App\Services\Schedule\ScheduleConditionEvaluator;
use Database\Seeders\CoreScheduleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 코어 스케줄 카탈로그(config/core.php schedules)와 CoreScheduleSeeder 테스트.
 *
 * 카탈로그는 7.2.0 직전 routes/console.php 의 코드 예약 32건을 1:1 로 옮긴 것이다 —
 * 이름·명령·일정·잠금 분이 그대로인지, 시더가 멱등이고 운영자 수정을 보존하는지 고정한다.
 *
 * 조합은 메서드마다 @scenario 로 단다(코어는 새 형식만 — 매니페스트 schedule-declaration-sync.yaml).
 */
class CoreScheduleSeederTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 7.2.0 직전 routes/console.php 코드 예약 → 카탈로그 name·명령·cron (1:1 대조 기준).
     *
     * queue:prune-failed 는 queue:prune-records 로 대체됐다(D8). 30초 주기 2건은 cron 이 없어 frequency 로 본다.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LEGACY_CONSOLE = [
        'layout-previews-cleanup' => ['layout-previews:cleanup', '*/30 * * * *'],
        'sanctum-prune' => ['sanctum:prune-expired --hours=24', '0 4 * * *'],
        'auth-clear-resets' => ['auth:clear-resets', '5 4 * * *'],
        'queue-prune-records' => ['queue:prune-records', '10 4 * * *'],
        'queue-prune-batches' => ['queue:prune-batches', '15 4 * * *'],
        'notification-cleanup' => ['notification:cleanup', '20 4 * * *'],
        'ext-bundles-cleanup' => ['ext-bundles:cleanup', '25 4 * * *'],
        'ext-static-cleanup' => ['ext-static:cleanup', '28 4 * * *'],
        'seo-prune-stats' => ['seo:prune-stats', '30 4 * * *'],
        'schedules-prune-history' => ['schedules:prune-history', '35 4 * * *'],
        'identity-prune-logs' => ['identity:prune-logs', '40 4 * * *'],
        'activity-log-prune' => ['activity-log:prune', '45 4 * * *'],
        'notification-log-prune' => ['notification-log:prune', '50 4 * * *'],
        'notification-delivery-prune' => ['notification-delivery:prune', '52 4 * * *'],
        'attachments-prune-orphans' => ['attachments:prune-orphans --scheduled', '55 4 * * *'],
        'storage-prune-leftovers' => ['storage:prune-leftovers', '0 5 * * *'],
        'privacy-process-retention' => ['privacy:process-retention --scheduled', '5 5 * * *'],
        'privacy-process-dormant' => ['privacy:process-dormant --scheduled', '10 5 * * *'],
        'auth-process-password-expiry' => ['auth:process-password-expiry --scheduled', '15 5 * * *'],
        'identity-expire-challenges' => ['identity:expire-challenges', '0 * * * *'],
        'analytics-prune' => ['analytics:prune', '10 5 * * *'],
        // 7.2.0 신규 (#869) — 옛 console.php 에 없던 예약. 카탈로그 전수 대조를 위해 함께 둔다.
        'thumbnails-prune' => ['thumbnails:prune --scheduled', '20 5 * * *'],
        'dashboard-broadcast-resources' => ['dashboard:broadcast-resources', 'everyThirtySeconds'],
        'analytics-archive-hour' => ['analytics:archive --period=hour --scheduled', '5 * * * *'],
        'analytics-archive-day' => ['analytics:archive --period=day --scheduled', '15 0 * * *'],
        'analytics-check-volume' => ['analytics:check-volume', '5 5 * * *'],
        'analytics-report-weekly' => ['analytics:report --period=weekly', '0 8 * * 1'],
        'analytics-report-monthly' => ['analytics:report --period=monthly', '0 8 1 * *'],
        'analytics-update-cloud-ranges' => ['analytics:update-cloud-ranges', '0 0 * * 0'],
        'analytics-broadcast-realtime' => ['analytics:broadcast-realtime', 'everyThirtySeconds'],
        'language-pack-check-updates' => ['language-pack:check-updates', '0 0 * * 0'],
        'sitemap-generate' => [GenerateSitemapJob::class, '0 2 * * *'],
        'geoip-update' => ['geoip:update', '0 3 * * 3'],
    ];

    /**
     * 카탈로그가 옛 console.php 등록과 이름·명령·일정까지 1:1 이다(누락·추가 0).
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects catalog_matches_legacy_console_registrations
     *
     * @return void 반환값 없음
     */
    public function test_catalog_matches_legacy_console_registrations(): void
    {
        $catalog = config('core.schedules');

        $this->assertEqualsCanonicalizing(array_keys(self::LEGACY_CONSOLE), array_keys($catalog));

        foreach (self::LEGACY_CONSOLE as $name => [$command, $cycle]) {
            $this->assertSame($command, $catalog[$name]['command'], $name);
            if ($cycle === 'everyThirtySeconds') {
                $this->assertSame('everyThirtySeconds', $catalog[$name]['frequency'] ?? null, $name);
            } else {
                $this->assertSame($cycle, $catalog[$name]['expression'], $name);
            }
            $this->assertIsArray($catalog[$name]['label'] ?? null, "{$name} label");
            $this->assertNotEmpty($catalog[$name]['label']['ko'] ?? '', "{$name} label.ko");
            $this->assertNotEmpty($catalog[$name]['label']['en'] ?? '', "{$name} label.en");
        }

        $this->assertCount(22, array_filter($catalog, fn (array $s) => ($s['group'] ?? null) === 'gc'));
    }

    /**
     * 카탈로그 artisan 명령은 모두 schedule_security 허용목록에 있고, job 은 허용 클래스 목록에 있다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects catalog_gc_commands_are_allowlisted
     *
     * @return void 반환값 없음
     */
    public function test_catalog_commands_are_allowlisted(): void
    {
        $allowlist = config('schedule_security.artisan.allowlist');
        $jobClasses = config('schedule_security.job.allowed_classes');

        foreach (config('core.schedules') as $name => $schedule) {
            if (($schedule['type'] ?? 'artisan') === 'job') {
                $this->assertContains($schedule['command'], $jobClasses, $name);

                continue;
            }
            $commandName = strtok($schedule['command'], ' ');
            $this->assertArrayHasKey($commandName, $allowlist, "{$name}: {$commandName}");
        }
    }

    /**
     * 카탈로그 전 항목의 실행 조건 키가 실제로 해석 가능하다(옛 결함 d — 존재하지 않는 키로 영구 비활성).
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects catalog_conditions_are_resolvable
     *
     * @return void 반환값 없음
     */
    public function test_every_catalog_condition_is_resolvable(): void
    {
        $evaluator = $this->app->make(ScheduleConditionEvaluator::class);
        $checked = 0;

        foreach (config('core.schedules') as $name => $schedule) {
            foreach ($schedule['enabled_condition'] ?? [] as $condition) {
                $this->assertTrue($evaluator->isResolvable($condition), "{$name}: {$condition['source']}:{$condition['key']}");
                $checked++;
            }
        }

        $this->assertSame(6, $checked, 'cloud-ranges 1 + broadcast-realtime 1 + sitemap 1 + geoip 3');
    }

    /**
     * 시더가 32건을 만들고, 잠금 분·주기·실행 방식·이력 방식이 옛 등록과 같다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects seeder_creates_core_rows, catalog_ttl_minutes_are_preserved
     *
     * @return void 반환값 없음
     */
    public function test_seeder_creates_rows_with_preserved_ttl(): void
    {
        Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->delete();

        $this->seed(CoreScheduleSeeder::class);

        $rows = Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->get()->keyBy('name');
        $this->assertCount(33, $rows);

        $expectedTtl = [
            'dashboard-broadcast-resources' => 5, 'analytics-broadcast-realtime' => 5, 'analytics-archive-hour' => 55,
            'analytics-archive-day' => 120, 'analytics-update-cloud-ranges' => 60, 'language-pack-check-updates' => 60,
            'sitemap-generate' => 30, 'geoip-update' => 60, 'sanctum-prune' => 0,
        ];
        foreach ($expectedTtl as $name => $minutes) {
            $this->assertSame($minutes, $rows[$name]->without_overlapping, $name);
        }

        $thirty = $rows['dashboard-broadcast-resources'];
        $this->assertSame(ScheduleFrequency::EveryThirtySeconds, $thirty->frequency);
        $this->assertFalse($thirty->on_one_server);
        $this->assertSame(ScheduleHistoryMode::FailuresOnly, $thirty->history_mode);

        $this->assertSame('queue', $rows['sitemap-generate']->dispatch_mode->value);
        $this->assertSame('job', $rows['sitemap-generate']->type->value);
        $this->assertFalse($rows['analytics-archive-hour']->notify_on_failure);
        $this->assertTrue($rows['sanctum-prune']->on_one_server);
        $this->assertSame('sync', $rows['sanctum-prune']->dispatch_mode->value);
        $this->assertArrayNotHasKey('group', $rows['sanctum-prune']->getAttributes());
        $this->assertNotNull($rows['sanctum-prune']->next_run_at);
    }

    /**
     * 두 번 돌려도 행 수·id 가 그대로다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=second_unchanged
     *
     * @effects seeder_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_seeder_is_idempotent(): void
    {
        $this->seed(CoreScheduleSeeder::class);
        $first = Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->orderBy('name')->pluck('id', 'name')->all();

        $this->seed(CoreScheduleSeeder::class);
        $second = Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->orderBy('name')->pluck('id', 'name')->all();

        $this->assertSame($first, $second);
        $this->assertCount(33, $second);
    }

    /**
     * 운영자가 바꾼 주기·활성·명칭(ko)은 재시딩 뒤에도 남고, 바꾸지 않은 필드는 카탈로그 값으로 갱신된다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=after_operator_edit
     *
     * @effects seeder_preserves_operator_overrides
     *
     * @return void 반환값 없음
     */
    public function test_seeder_preserves_operator_overrides(): void
    {
        $this->seed(CoreScheduleSeeder::class);

        $row = Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->where('name', 'sanctum-prune')->firstOrFail();
        $row->update([
            'expression' => '30 3 * * *',
            'is_active' => false,
            'label' => array_merge($row->label, ['ko' => '운영자 명칭']),
        ]);
        // 카탈로그 쪽 변화를 흉내 — 운영자가 손대지 않은 필드는 덮여야 한다
        Schedule::whereKey($row->id)->toBase()->update(['timeout' => 999]);

        $this->seed(CoreScheduleSeeder::class);

        $fresh = $row->fresh();
        $this->assertSame('30 3 * * *', $fresh->expression);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('운영자 명칭', $fresh->label['ko']);
        $this->assertSame(config('core.schedules.sanctum-prune.label.en'), $fresh->label['en']);
        $this->assertNull($fresh->timeout, '운영자가 바꾸지 않은 timeout 은 카탈로그 값(null)으로 돌아온다');
    }

    /**
     * 카탈로그에서 빠진 코어 행은 지워지고, 직접 등록 행은 건드리지 않는다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects seeder_removes_rows_dropped_from_catalog, seeder_leaves_custom_rows_alone
     *
     * @return void 반환값 없음
     */
    public function test_seeder_removes_stale_core_rows_only(): void
    {
        $stale = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create(['name' => 'removed-from-catalog']);
        $custom = Schedule::factory()->create(['name' => 'removed-from-catalog']);
        $stale->histories()->create(['started_at' => now(), 'status' => 'success', 'trigger_type' => 'manual']);

        $this->seed(CoreScheduleSeeder::class);

        $this->assertNull(Schedule::find($stale->id));
        $this->assertSame(0, ScheduleHistory::where('schedule_id', $stale->id)->count());
        $this->assertNotNull(Schedule::find($custom->id));
    }

    /**
     * core.schedules.config 필터가 시딩 직전 카탈로그에 로케일 키를 더한다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects catalog_filter_runs_before_sync
     *
     * @return void 반환값 없음
     */
    public function test_catalog_filter_runs_before_sync(): void
    {
        $callback = function (array $catalog): array {
            $catalog['sanctum-prune']['label']['ja'] = 'トークン整理';

            return $catalog;
        };
        HookManager::addFilter('core.schedules.config', $callback, 99);

        try {
            $this->seed(CoreScheduleSeeder::class);
        } finally {
            HookManager::removeFilter('core.schedules.config', $callback);
        }

        $row = Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->where('name', 'sanctum-prune')->firstOrFail();
        $this->assertSame('トークン整理', $row->label['ja']);
    }

    /**
     * 코어 업데이트의 설정 재동기화가 스케줄 시더를 부른다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects core_resync_runs_schedule_seeder
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_seeds_schedules(): void
    {
        Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->delete();

        $this->app->make(CoreUpdateService::class)->reloadCoreConfigAndResync();

        $this->assertSame(33, Schedule::ownedBy(ExtensionOwnerType::Core, 'core')->count());
    }
}
