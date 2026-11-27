<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 확장 스케줄 선언 동기화 테스트 (ExtensionScheduleSyncHelper · Module/PluginManager 결선).
 *
 * 조합은 메서드마다 @scenario 로 단다(owner·declaration_shape·sync_run — 매니페스트 schedule-declaration-sync.yaml).
 */
class ExtensionScheduleSyncHelperTest extends TestCase
{
    use DatabaseTransactions;

    private ExtensionScheduleSyncHelper $helper;

    /**
     * 동기화 헬퍼를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = $this->app->make(ExtensionScheduleSyncHelper::class);
    }

    /**
     * 옛 형식은 name 생성(겹치면 -2)·주기 메서드명 → cron·enabled_config → 소유 설정 조건으로 바뀐다.
     *
     * @scenario declaration_shape=legacy_schema, owner=module, sync_run=first
     *
     * @effects legacy_declaration_is_normalized
     *
     * @return void 반환값 없음
     */
    public function test_legacy_declarations_are_normalized(): void
    {
        $legacy = [
            ['command' => 'vendor-probe:update --scheduled', 'schedule' => '30 11 * * *', 'description' => '정기 갱신', 'enabled_config' => 'vendor-probe.auto_manage'],
            ['command' => 'vendor-probe:update --scheduled', 'schedule' => 'hourly', 'description' => '재시도'],
            ['command' => 'vendor-probe:prune', 'schedule' => 'everyThirtyMinutes'],
            ['command' => 'vendor-probe:tick', 'schedule' => 'everyThirtySeconds'],
            ['schedule' => 'daily'],
        ];

        $out = $this->helper->normalizeLegacySchedules($legacy, ExtensionOwnerType::Plugin, 'vendor-probe');

        $this->assertSame(
            ['vendor-probe-update-scheduled', 'vendor-probe-update-scheduled-2', 'vendor-probe-prune', 'vendor-probe-tick'],
            array_column($out, 'name'),
        );
        $this->assertSame(
            [['source' => 'plugin_setting', 'key' => 'auto_manage', 'operator' => 'truthy', 'default' => true]],
            $out[0]['enabled_condition'],
        );

        $normalized = array_map(fn (array $d) => $this->helper->normalizeDefinition($d), $out);
        $this->assertSame('30 11 * * *', $normalized[0]['expression']);
        $this->assertSame('0 * * * *', $normalized[1]['expression']);
        $this->assertSame('*/30 * * * *', $normalized[2]['expression']);
        $this->assertSame('everyThirtySeconds', $normalized[3]['frequency']);
        $this->assertSame(['ko' => '정기 갱신'], $normalized[0]['label']);

        $module = $this->helper->normalizeLegacySchedules(
            [['command' => 'm:run', 'schedule' => 'daily', 'enabled_config' => 'flag']],
            ExtensionOwnerType::Module,
            'vendor-m',
        );
        $this->assertSame('module_setting', $module[0]['enabled_condition'][0]['source']);
    }

    /**
     * 옛 형식 선언 1건을 등록부 행 속성으로 바꿉니다(옛 형식 정규화 → 기본값 채움 — 동기화가 거치는 두 단계).
     *
     * @param  array<string, mixed>  $definition  옛 형식 getSchedules() 항목
     * @param  ExtensionOwnerType  $owner  소유 타입
     * @return array<string, mixed> 등록부 행 속성
     */
    private function legacyRowAttributes(array $definition, ExtensionOwnerType $owner): array
    {
        $out = $this->helper->normalizeLegacySchedules([$definition], $owner, 'i161-ext');
        $this->assertCount(1, $out);

        return $this->helper->normalizeDefinition($out[0]);
    }

    /**
     * 확장 예약 작업은 지정하지 않아도 중복 실행 방지(1440분 뒤 만료)·한 서버에서만 실행이 걸린다(공개 #161 F3) — 길어진 매시
     * 작업이 다음 회차와 겹치거나, 다중 서버에서 같은 작업이 서버 수만큼 돌았다. 모듈·플러그인 모두 같다.
     *
     * 7.1.5 는 routes/console.php 등록 블록이 이 기본값을 걸었다. 7.2.0 은 선언을 등록부(schedules) 행으로 옮기므로
     * 옛 형식 정규화가 같은 기본값을 행 속성으로 채운다.
     *
     * @scenario case=ext_schedule_default
     *
     * @effects extension_schedule_defaults_to_overlap_guards
     *
     * @return void 반환값 없음
     */
    public function test_extension_schedule_defaults_to_without_overlapping_and_one_server(): void
    {
        foreach ([ExtensionOwnerType::Module, ExtensionOwnerType::Plugin] as $owner) {
            $row = $this->legacyRowAttributes(['command' => 'i161-ext:hourly', 'schedule' => 'hourly'], $owner);

            $this->assertSame(1440, $row['without_overlapping'], "{$owner->value}: 기본으로 중복 실행 방지(잠금은 1440분 뒤 만료)");
            $this->assertTrue($row['on_one_server'], "{$owner->value}: 기본으로 한 서버에서만 실행");
        }
    }

    /**
     * `without_overlapping => false` 면 중복 실행 방지를 걸지 않는다 — 겹쳐 돌아도 되는 짧은 작업의 선택지다. 한 서버 실행은
     * 그대로 남는다.
     *
     * @scenario case=ext_schedule_overlap_opt_out
     *
     * @effects extension_schedule_overlap_guard_opt_out
     *
     * @return void 반환값 없음
     */
    public function test_without_overlapping_false_opts_out(): void
    {
        foreach ([ExtensionOwnerType::Module, ExtensionOwnerType::Plugin] as $owner) {
            $row = $this->legacyRowAttributes(
                ['command' => 'i161-ext:free', 'schedule' => 'everyMinute', 'without_overlapping' => false],
                $owner,
            );

            $this->assertSame(0, $row['without_overlapping'], "{$owner->value}: 중복 실행 방지를 끈다");
            $this->assertTrue($row['on_one_server'], "{$owner->value}: 한 서버 실행은 그대로");
        }
    }

    /**
     * `without_overlapping` 에 분 단위 정수를 주면 그 시간 뒤 잠금이 만료된다 — 비정상 종료가 하루 동안 다음 실행을 막지 않게
     * 작업 길이에 맞춘다.
     *
     * @scenario case=ext_schedule_overlap_minutes
     *
     * @effects extension_schedule_overlap_expiry_minutes
     *
     * @return void 반환값 없음
     */
    public function test_without_overlapping_minutes_sets_expiry(): void
    {
        $module = $this->legacyRowAttributes(
            ['command' => 'i161-ext:short', 'schedule' => '*/5 * * * *', 'without_overlapping' => 10],
            ExtensionOwnerType::Module,
        );
        $this->assertSame(10, $module['without_overlapping']);
        $this->assertSame('*/5 * * * *', $module['expression']);

        $plugin = $this->legacyRowAttributes(
            ['command' => 'i161-ext:short', 'schedule' => 'hourly', 'without_overlapping' => 55],
            ExtensionOwnerType::Plugin,
        );
        $this->assertSame(55, $plugin['without_overlapping']);
    }

    /**
     * `on_one_server => false` 면 서버마다 실행한다 — 서버별 로컬 정리 작업의 선택지다. 중복 실행 방지는 그대로 남는다.
     *
     * @scenario case=ext_schedule_one_server_opt_out
     *
     * @effects extension_schedule_one_server_opt_out
     *
     * @return void 반환값 없음
     */
    public function test_on_one_server_false_opts_out(): void
    {
        foreach ([ExtensionOwnerType::Module, ExtensionOwnerType::Plugin] as $owner) {
            $row = $this->legacyRowAttributes(
                ['command' => 'i161-ext:local', 'schedule' => 'daily', 'on_one_server' => false],
                $owner,
            );

            $this->assertFalse($row['on_one_server'], "{$owner->value}: 한 서버 실행을 끈다");
            $this->assertSame(1440, $row['without_overlapping'], "{$owner->value}: 중복 실행 방지는 그대로");
        }
    }

    /**
     * 첫 동기화 → 운영자 수정 → 재동기화: 수정은 보존, 선언에서 빠진 행은 정리, 직접 등록 행은 그대로.
     *
     * @scenario declaration_shape=new_schema, owner=module, sync_run=after_declaration_removed
     *
     * @effects extension_sync_preserves_overrides_and_removes_stale, seeder_leaves_custom_rows_alone
     *
     * @return void 반환값 없음
     */
    public function test_sync_preserves_overrides_and_removes_stale(): void
    {
        $declarations = [
            ['name' => 'keep', 'label' => ['ko' => '유지', 'en' => 'Keep'], 'command' => 'vendor-m:keep', 'expression' => '0 1 * * *', 'without_overlapping' => 30],
            ['name' => 'drop', 'command' => 'vendor-m:drop', 'expression' => '0 2 * * *'],
        ];
        $custom = Schedule::factory()->create(['name' => 'drop']);

        $first = $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', $declarations);
        $this->assertSame(['keep', 'drop'], $first['synced']);

        $keep = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'keep')->firstOrFail();
        $this->assertSame(30, $keep->without_overlapping);
        $this->assertTrue($keep->on_one_server);
        $keep->update(['expression' => '15 1 * * *', 'is_active' => false]);

        $declarations[0]['description'] = ['ko' => '새 설명'];
        $second = $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', [$declarations[0]]);

        $this->assertSame(1, $second['removed']);
        $fresh = $keep->fresh();
        $this->assertSame('15 1 * * *', $fresh->expression);
        $this->assertFalse($fresh->is_active);
        $this->assertSame(['ko' => '새 설명'], $fresh->description);
        $this->assertFalse(Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'drop')->exists());
        $this->assertNotNull($custom->fresh());
    }

    /**
     * 옛 형식이 만든 자동 이름 행은 새 형식의 name 으로 이어받는다 — 운영자 수정·이력이 남고 새 행이 생기지 않는다.
     *
     * 코어를 먼저 7.2.0 으로 올리면 아직 옛 버전인 확장의 선언이 자동 이름(`vendor-m-aggregate-stats`)으로 행을 만든다.
     * 그 사이 운영자가 끄거나 주기를 바꾼 뒤 확장을 새 형식(name 명시)으로 업데이트해도 그 행이 그대로 이어져야 한다.
     *
     * @scenario declaration_shape=new_schema, owner=module, sync_run=after_format_upgrade
     *
     * @effects legacy_named_row_is_adopted_by_new_name
     *
     * @return void 반환값 없음
     */
    public function test_module_legacy_named_row_is_adopted_by_new_name(): void
    {
        $this->assertLegacyRowAdopted(ExtensionOwnerType::Module, 'vendor-m', 'vendor-m:aggregate-stats', 'vendor-m-aggregate-stats', 'aggregate-stats');
    }

    /**
     * 플러그인도 같은 경로로 이어받는다 — 옵션이 붙은 명령(자동 이름에 옵션까지 들어간다)과 같은 명령 2건(`-2`).
     *
     * @scenario declaration_shape=new_schema, owner=plugin, sync_run=after_format_upgrade
     *
     * @effects legacy_named_row_is_adopted_by_new_name
     *
     * @return void 반환값 없음
     */
    public function test_plugin_legacy_named_rows_are_adopted_by_new_names(): void
    {
        // @scenario declaration_shape=legacy_schema, owner=plugin, sync_run=first
        $legacy = [
            ['command' => 'vendor-p:update --scheduled', 'schedule' => '30 11 * * *'],
            ['command' => 'vendor-p:update --scheduled', 'schedule' => 'hourly'],
        ];
        $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', $legacy);
        $first = Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->where('name', 'vendor-p-update-scheduled')->firstOrFail();
        $second = Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->where('name', 'vendor-p-update-scheduled-2')->firstOrFail();
        $first->update(['expression' => '0 12 * * *']);

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['name' => 'update-rates', 'command' => 'vendor-p:update --scheduled', 'expression' => '30 11 * * *'],
            ['name' => 'retry-rates', 'command' => 'vendor-p:update --scheduled', 'expression' => '0 * * * *'],
        ]);

        $this->assertSame(0, $result['removed']);
        $this->assertSame(['retry-rates', 'update-rates'], Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->orderBy('name')->pluck('name')->all());
        $this->assertSame('update-rates', $first->fresh()->name);
        $this->assertSame('0 12 * * *', $first->fresh()->expression);
        $this->assertSame('retry-rates', $second->fresh()->name);
    }

    /**
     * 옛 형식 선언도 운영자가 바꾼 주기·사용 여부를 다시 동기화할 때 덮지 않는다.
     *
     * @scenario declaration_shape=legacy_schema, owner=module, sync_run=after_operator_edit
     *
     * @effects extension_sync_preserves_overrides_and_removes_stale
     *
     * @return void 반환값 없음
     */
    public function test_legacy_module_resync_keeps_operator_edit(): void
    {
        $legacy = [['command' => 'vendor-m:tidy', 'schedule' => 'daily']];
        $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', $legacy);
        $row = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'vendor-m-tidy')->firstOrFail();
        $row->update(['expression' => '20 6 * * *', 'is_active' => false]);

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', $legacy);

        $this->assertSame(['vendor-m-tidy'], $result['synced']);
        $this->assertSame('20 6 * * *', $row->fresh()->expression);
        $this->assertFalse($row->fresh()->is_active);
    }

    /**
     * 플러그인이 기존 선언을 빼고 잘못된 선언만 남기면, 뺀 행은 정리되고 잘못된 선언은 행을 만들지 않는다.
     *
     * @scenario declaration_shape=invalid, owner=plugin, sync_run=after_declaration_removed
     *
     * @effects invalid_declaration_is_skipped_and_row_kept
     *
     * @return void 반환값 없음
     */
    public function test_plugin_invalid_declaration_after_removal(): void
    {
        $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['name' => 'old-job', 'command' => 'vendor-p:old', 'expression' => '0 1 * * *'],
        ]);

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['name' => 'bad-job', 'command' => 'vendor-p:bad', 'expression' => '0 1 * * *', 'without_overlapping' => 99999],
        ]);

        $this->assertArrayHasKey('bad-job', $result['skipped']);
        $this->assertSame(1, $result['removed']);
        $this->assertFalse(Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->exists());
    }

    /**
     * 플러그인 새 형식 선언을 바뀐 것 없이 다시 동기화하면 행·운영자 값이 그대로다.
     *
     * @scenario declaration_shape=new_schema, owner=plugin, sync_run=second_unchanged
     *
     * @effects extension_sync_preserves_overrides_and_removes_stale
     *
     * @return void 반환값 없음
     */
    public function test_plugin_new_schema_second_sync_is_unchanged(): void
    {
        $declarations = [['name' => 'nightly', 'label' => ['ko' => '야간', 'en' => 'Nightly'], 'command' => 'vendor-p:nightly', 'expression' => '0 3 * * *']];
        $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', $declarations);
        $row = Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->where('name', 'nightly')->firstOrFail();

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', $declarations);

        $this->assertSame(['nightly'], $result['synced']);
        $this->assertSame(0, $result['removed']);
        $fresh = $row->fresh();
        $this->assertSame($row->id, $fresh->id);
        $this->assertSame('0 3 * * *', $fresh->expression);
        $this->assertSame(1, Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->count());
    }

    /**
     * 옛 자동 이름 행을 만들고 운영자 수정·이력을 남긴 뒤 새 형식으로 동기화해 같은 행이 이어지는지 확인합니다.
     *
     * @param  ExtensionOwnerType  $owner  소유 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $command  선언 명령
     * @param  string  $legacyName  옛 형식이 만드는 자동 이름
     * @param  string  $newName  새 형식 선언의 name
     * @return void 반환값 없음
     */
    private function assertLegacyRowAdopted(ExtensionOwnerType $owner, string $identifier, string $command, string $legacyName, string $newName): void
    {
        $this->helper->syncSchedules($owner, $identifier, [['command' => $command, 'schedule' => 'daily']]);
        $row = Schedule::ownedBy($owner, $identifier)->where('name', $legacyName)->firstOrFail();
        $row->update(['expression' => '45 3 * * *', 'is_active' => false]);
        $history = $row->histories()->create(['trigger_type' => 'scheduled', 'status' => 'success', 'started_at' => now()]);

        $result = $this->helper->syncSchedules($owner, $identifier, [
            ['name' => $newName, 'label' => ['ko' => '새 이름', 'en' => 'New name'], 'command' => $command, 'expression' => '0 0 * * *'],
        ]);

        $this->assertSame(0, $result['removed']);
        $rows = Schedule::ownedBy($owner, $identifier)->get();
        $this->assertCount(1, $rows);
        $fresh = $rows->first();
        $this->assertSame($row->id, $fresh->id);
        $this->assertSame($newName, $fresh->name);
        $this->assertSame('45 3 * * *', $fresh->expression);
        $this->assertFalse($fresh->is_active);
        $this->assertEqualsCanonicalizing(['ko' => '새 이름', 'en' => 'New name'], $fresh->label);
        $this->assertNotNull(ScheduleHistory::find($history->id));
    }

    /**
     * 잘못된 선언은 건너뛰고(처음 들어오는 job-b), 운영자가 고친 기존 행(job-a)도 지우거나 덮지 않는다.
     *
     * @scenario declaration_shape=invalid, owner=module, sync_run=first
     *
     * @effects invalid_declaration_is_skipped_and_row_kept
     *
     * @return void 반환값 없음
     */
    public function test_invalid_declaration_is_skipped_and_row_kept(): void
    {
        // @scenario declaration_shape=invalid, owner=module, sync_run=after_operator_edit
        $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', [
            ['name' => 'job-a', 'command' => 'vendor-m:a', 'expression' => '0 1 * * *'],
        ]);
        Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'job-a')->firstOrFail()
            ->update(['expression' => '45 1 * * *']);

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', [
            ['name' => 'job-a', 'command' => 'migrate:fresh', 'expression' => '0 1 * * *'],
            ['name' => 'job-b', 'type' => 'shell', 'command' => 'php /x.php', 'expression' => '0 1 * * *'],
        ]);

        $this->assertSame([], $result['synced']);
        $this->assertSame(['job-a', 'job-b'], array_keys($result['skipped']));
        $this->assertSame(0, $result['removed']);
        $row = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'job-a')->firstOrFail();
        $this->assertSame('vendor-m:a', $row->command, '거부된 선언이 기존 명령을 덮지 않는다');
        $this->assertSame('45 1 * * *', $row->expression, '운영자 수정도 그대로');
        $this->assertFalse(Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'job-b')->exists());
    }

    /**
     * 플러그인 — 운영자가 고친 행에 잘못된 선언이 오면 건너뛰고 수정을 보존하며, 같은 무효 선언을 다시 받아도 그대로다.
     *
     * @scenario declaration_shape=invalid, owner=plugin, sync_run=after_operator_edit
     *
     * @effects invalid_declaration_is_skipped_and_row_kept
     *
     * @return void 반환값 없음
     */
    public function test_plugin_invalid_declaration_keeps_operator_edit(): void
    {
        // @scenario declaration_shape=invalid, owner=plugin, sync_run=second_unchanged
        $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['name' => 'refresh', 'command' => 'vendor-p:refresh', 'expression' => '0 6 * * *'],
        ]);
        $row = Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->where('name', 'refresh')->firstOrFail();
        $row->update(['is_active' => false]);

        $invalid = [['name' => 'refresh', 'command' => 'vendor-p:refresh', 'expression' => 'every morning']];
        $first = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', $invalid);
        $second = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', $invalid);

        foreach ([$first, $second] as $result) {
            $this->assertSame(['refresh'], array_keys($result['skipped']));
            $this->assertSame(0, $result['removed']);
        }
        $fresh = $row->fresh();
        $this->assertSame('0 6 * * *', $fresh->expression);
        $this->assertFalse($fresh->is_active);
        $this->assertSame(['is_active'], $fresh->user_overrides);
    }

    /**
     * 모듈 옛 형식 — 같은 선언을 두 번 동기화해도 행 id·값이 그대로이고 운영자 수정 기록이 생기지 않는다.
     *
     * @scenario declaration_shape=legacy_schema, owner=module, sync_run=second_unchanged
     *
     * @effects legacy_declaration_is_normalized
     *
     * @return void 반환값 없음
     */
    public function test_legacy_module_second_sync_is_unchanged(): void
    {
        $legacy = [
            ['command' => 'vendor-m:aggregate-stats', 'schedule' => 'daily', 'description' => '통계 집계'],
            ['command' => 'vendor-m:prune --scheduled', 'schedule' => '0 4 * * *', 'enabled_config' => 'vendor-m.cleanup.enabled'],
        ];

        $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', $legacy);
        $before = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->orderBy('name')->get(['id', 'name', 'expression', 'enabled_condition', 'user_overrides']);

        $this->helper->syncSchedules(ExtensionOwnerType::Module, 'vendor-m', $legacy);
        $after = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->orderBy('name')->get(['id', 'name', 'expression', 'enabled_condition', 'user_overrides']);

        $this->assertSame($before->toArray(), $after->toArray());
        $this->assertSame(['vendor-m-aggregate-stats', 'vendor-m-prune-scheduled'], $after->pluck('name')->all());
        $this->assertSame('0 0 * * *', $after[0]->expression);
        $this->assertSame('cleanup.enabled', $after[1]->enabled_condition[0]['key']);
        $this->assertNull($after[0]->user_overrides);
    }

    /**
     * 플러그인 옛 형식 — 다음 버전에서 빠진 선언의 행은 정리된다.
     *
     * @scenario declaration_shape=legacy_schema, owner=plugin, sync_run=after_declaration_removed
     *
     * @effects extension_sync_preserves_overrides_and_removes_stale
     *
     * @return void 반환값 없음
     */
    public function test_plugin_legacy_declaration_removed(): void
    {
        $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['command' => 'vendor-p:update --scheduled', 'schedule' => '30 11 * * *'],
            ['command' => 'vendor-p:update --scheduled --retry', 'schedule' => 'hourly'],
        ]);
        $this->assertSame(2, Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->count());

        $result = $this->helper->syncSchedules(ExtensionOwnerType::Plugin, 'vendor-p', [
            ['command' => 'vendor-p:update --scheduled', 'schedule' => '30 11 * * *'],
        ]);

        $this->assertSame(1, $result['removed']);
        $this->assertSame(['vendor-p-update-scheduled'], Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->pluck('name')->all());
    }

    /**
     * 확장 제거(데이터 삭제) 정리는 그 확장의 행과 이력만 지운다.
     *
     * @scenario declaration_shape=new_schema, owner=plugin, sync_run=after_declaration_removed
     *
     * @effects uninstall_with_data_removes_schedules_and_history
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_for_extension_removes_rows_and_history(): void
    {
        $owned = Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->create();
        $other = Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-other')->create();
        $history = $owned->histories()->create(['started_at' => now(), 'status' => 'success', 'trigger_type' => 'manual']);

        $deleted = $this->helper->cleanupSchedulesForExtension(ExtensionOwnerType::Plugin, 'vendor-p');

        $this->assertSame(1, $deleted);
        $this->assertNull(Schedule::find($owned->id));
        $this->assertNull(ScheduleHistory::find($history->id));
        $this->assertNotNull(Schedule::find($other->id));
    }

    /**
     * ModuleManager 동기화 결선 — 옛 형식 선언이 DB 로 들어가고, 선언이 빈 배열이면 기존 행을 지우지 않는다.
     *
     * @scenario declaration_shape=legacy_schema, owner=module, sync_run=first
     *
     * @effects legacy_declaration_is_normalized, empty_declaration_blocks_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_module_manager_sync_and_empty_guard(): void
    {
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn('vendor-m');
        $module->shouldReceive('getSchedules')->andReturn(
            [['command' => 'vendor-m:aggregate', 'schedule' => '0 3 * * *', 'description' => '집계']],
            [],
        );

        $sync = new ReflectionMethod(ModuleManager::class, 'syncModuleSchedules');
        $manager = $this->app->make(ModuleManager::class);

        $sync->invoke($manager, $module);
        $row = Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-m')->where('name', 'vendor-m-aggregate')->first();
        $this->assertNotNull($row);
        $this->assertSame('집계', $row->localizedLabel('ko'));

        $sync->invoke($manager, $module);
        $this->assertTrue(Schedule::whereKey($row->id)->exists(), '빈 선언은 정리를 막는다');
    }

    /**
     * PluginManager 동기화 결선 — 새 형식 선언이 소유 플러그인으로 들어간다.
     *
     * @scenario declaration_shape=new_schema, owner=plugin, sync_run=first
     *
     * @effects extension_sync_preserves_overrides_and_removes_stale
     *
     * @return void 반환값 없음
     */
    public function test_plugin_manager_sync(): void
    {
        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getIdentifier')->andReturn('vendor-p');
        $plugin->shouldReceive('getSchedules')->andReturn([
            ['name' => 'refresh', 'type' => 'artisan', 'command' => 'vendor-p:refresh', 'expression' => '30 11 * * *', 'timezone' => 'Asia/Seoul'],
        ]);

        (new ReflectionMethod(PluginManager::class, 'syncPluginSchedules'))->invoke($this->app->make(PluginManager::class), $plugin);

        $row = Schedule::ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->where('name', 'refresh')->firstOrFail();
        $this->assertSame('Asia/Seoul', $row->timezone);
        $this->assertSame('30 11 * * *', $row->expression);
    }

    /**
     * 확장 제거 모달의 공유 데이터 집계에 그 확장의 스케줄 수가 나온다.
     *
     * @scenario declaration_shape=new_schema, owner=module, sync_run=first
     *
     * @effects uninstall_modal_counts_schedules
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_modal_counts_schedules(): void
    {
        Schedule::factory()->count(2)->ownedBy(ExtensionOwnerType::Module, 'vendor-m')->create();
        Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-m')->create();

        $resolve = new ReflectionMethod(ModuleManager::class, 'resolveExtensionSharedRecords');
        $records = collect($resolve->invoke($this->app->make(ModuleManager::class), 'module', 'vendor-m'))->keyBy('label_key');

        $this->assertSame(2, $records['schedules']['count'] ?? null);
    }
}
