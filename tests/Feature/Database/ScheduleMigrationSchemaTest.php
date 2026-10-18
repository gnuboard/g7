<?php

namespace Tests\Feature\Database;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleHistoryMode;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * schedules DB 등록부 스키마(7.2.0 마이그레이션 000001~000003·000005)와 Schedule 모델 계약 테스트.
 *
 * 마이그레이션 왕복(up/down/up)과 옛 형식 데이터 변환은 DDL 이라 트랜잭션 격리 테스트로 돌릴 수 없다 —
 * 전용 DB 실측으로 확인했다(이력 문서). 여기서는 적용 결과 스키마와 모델 동작을 고정한다.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 schedule-registry-model.yaml).
 */
class ScheduleMigrationSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 실행 방식·이력 방식·조건·다국어 명칭 등 등록부 컬럼이 모두 있다.
     *
     * @effects schema_has_registry_columns
     *
     * @return void 반환값 없음
     */
    public function test_registry_columns_exist(): void
    {
        foreach (['label', 'command_class', 'timezone', 'on_one_server', 'dispatch_mode', 'queue_connection',
            'queue_name', 'history_mode', 'notify_on_failure', 'enabled_condition'] as $column) {
            $this->assertTrue(Schema::hasColumn('schedules', $column), "schedules.{$column} 컬럼이 없다");
        }

        $columns = collect(Schema::getColumns('schedules'))->keyBy('name');
        $this->assertStringContainsString("'job'", $columns['type']['type']);
        $this->assertStringContainsString("'everyThirtySeconds'", $columns['frequency']['type']);
        $this->assertSame('json', strtolower($columns['description']['type_name']));
    }

    /**
     * without_overlapping 은 분 단위 정수다 — 5·55·120 같은 잠금 유지 시간이 그대로 저장된다(옛 결함 c).
     *
     * @effects without_overlapping_is_minutes_integer
     *
     * @return void 반환값 없음
     */
    public function test_without_overlapping_stores_minutes(): void
    {
        $columns = collect(Schema::getColumns('schedules'))->keyBy('name');
        $this->assertSame('smallint', strtolower($columns['without_overlapping']['type_name']));

        foreach ([5, 55, 120, 1440, 10080] as $minutes) {
            $schedule = Schedule::factory()->create(['without_overlapping' => $minutes]);
            $this->assertSame($minutes, $schedule->fresh()->without_overlapping);
        }
    }

    /**
     * 소유자·이름 유니크와 이력 조회 인덱스가 있다.
     *
     * @effects owner_name_unique_index_exists, history_schedule_started_index_exists
     *
     * @return void 반환값 없음
     */
    public function test_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('schedules', 'uq_schedules_owner_name'));
        $this->assertTrue(Schema::hasIndex('schedule_histories', 'idx_schedule_histories_schedule_started'));

        $unique = collect(Schema::getIndexes('schedules'))->firstWhere('name', 'uq_schedules_owner_name');
        $this->assertSame(['extension_type', 'extension_identifier', 'name'], $unique['columns']);
        $this->assertTrue($unique['unique']);
    }

    /**
     * 표시 명칭은 label[로케일] → 폴백 로케일 → name 순으로 해석한다.
     *
     * @effects label_resolves_locale_then_fallback_then_name
     *
     * @return void 반환값 없음
     */
    public function test_localized_label_and_description(): void
    {
        config(['app.fallback_locale' => 'ko']);
        $schedule = Schedule::factory()->create([
            'name' => 'label-probe',
            'label' => ['ko' => '라벨', 'en' => 'Label'],
            'description' => ['ko' => '설명'],
        ]);

        $this->assertSame('Label', $schedule->localizedLabel('en'));
        $this->assertSame('라벨', $schedule->localizedLabel('ja'));
        $this->assertSame('설명', $schedule->localizedDescription('en'));

        $schedule->update(['label' => null, 'description' => null]);
        $this->assertSame('label-probe', $schedule->fresh()->localizedLabel('ko'));
        $this->assertNull($schedule->fresh()->localizedDescription('ko'));
    }

    /**
     * 소유 여부·소유 확장 활성 여부·소유자 스코프(null = 직접 등록).
     *
     * @scenario owner=core, frequency=cron
     *
     * @effects owned_rows_report_owner_activity, owned_by_scope_null_means_custom
     *
     * @return void 반환값 없음
     */
    public function test_ownership_helpers(): void
    {
        // @scenario owner=module, frequency=cron
        // @scenario owner=custom, frequency=cron
        $core = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create();
        $module = Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-not-installed')->create();
        $custom = Schedule::factory()->create();

        $this->assertTrue($core->isOwned());
        $this->assertTrue($core->ownerIsActive());
        $this->assertFalse($custom->isOwned());
        $this->assertTrue($custom->ownerIsActive());
        $this->assertTrue($module->isOwned());
        $this->assertFalse($module->ownerIsActive(), '설치되지 않은(비활성) 모듈 소유 행은 실행 불가');

        $customIds = Schedule::ownedBy(null)->pluck('id')->all();
        $this->assertContains($custom->id, $customIds);
        $this->assertNotContains($core->id, $customIds);
        $this->assertSame([$module->id], Schedule::ownedBy(ExtensionOwnerType::Module, 'vendor-not-installed')->pluck('id')->all());
    }

    /**
     * 운영자 수정은 다국어는 dot-path, 나머지는 컬럼명으로 user_overrides 에 남고, command 는 추적하지 않는다.
     *
     * @effects operator_edit_records_dot_path_overrides
     *
     * @return void 반환값 없음
     */
    public function test_operator_edit_records_overrides(): void
    {
        $schedule = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create([
            'label' => ['ko' => '원래', 'en' => 'Original'],
            'dispatch_mode' => 'sync',
        ]);

        $schedule->update([
            'label' => ['ko' => '바꿈', 'en' => 'Original'],
            'without_overlapping' => 60,
            'dispatch_mode' => 'queue',
            'command' => 'cache:clear',
        ]);

        $overrides = $schedule->fresh()->user_overrides;
        $this->assertEqualsCanonicalizing(['label.ko', 'without_overlapping', 'dispatch_mode'], $overrides);
        $this->assertSame(ScheduleDispatchMode::Queue, $schedule->fresh()->dispatch_mode);
        $this->assertSame(ScheduleHistoryMode::All, $schedule->fresh()->history_mode);
    }
}
