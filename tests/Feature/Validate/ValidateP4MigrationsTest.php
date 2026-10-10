<?php

namespace Tests\Feature\Validate;

use Tests\Support\RecordingSchemaBuilder;
use Tests\TestCase;

/**
 * 코어 마이그레이션 재실행 가드 검증 (#599 validate P4)
 *
 * 재실행 가드는 "앞선 실행이 중간에 멈춘 상태" 에서만 드러나는데, 그 상태를 공유 테스트 DB 에
 * 만들면 DDL 이 암묵 커밋되어 테이블이 망가진다. 그래서 스키마 호출을 {@see RecordingSchemaBuilder}
 * 로 바꿔 끼워 **실제 DDL 없이** 무엇을 내려 했는지만 본다. DB 에 쓰지 않으므로 트랜잭션 격리가 필요 없다.
 */
class ValidateP4MigrationsTest extends TestCase
{
    /** 활동 로그 i18n 전환 마이그레이션. */
    private const ACTIVITY_LOG_I18N = 'database/migrations/2026_04_01_000657_update_i18n_fields_in_activity_logs_table.php';

    /** 레이아웃 확장 조각 식별자 마이그레이션. */
    private const LAYOUT_FRAGMENT_KEY = 'database/migrations/2026_09_11_000001_add_fragment_key_to_template_layout_extensions_table.php';

    /** 전환 전 활동 로그의 공통 컬럼 (마이그레이션이 after()·인덱스로 참조하는 것). */
    private const BASE_COLUMNS = ['action', 'properties', 'loggable_type', 'loggable_id', 'log_type', 'created_at'];

    /**
     * 활동 로그 i18n 마이그레이션은 `description` 삭제까지 마치고 멈춘 설치본에서 다시 돌면
     * 삭제를 건너뛰고 남은 컬럼·인덱스를 마저 만든다. 그 다음 실행은 아무것도 내지 않는다.
     *
     * 수정 전 실패 이유: `dropColumn('description')` 에 `hasColumn` 가드가 없어, 삭제 뒤 단계에서 멈춘
     * 설치본을 다시 돌리면 "Can't DROP column description" 으로 매번 첫 단계에서 실패한다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects activity_log_i18n_rerun_skips_the_already_dropped_column
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_i18n_rerun_skips_the_already_dropped_column(): void
    {
        $schema = new RecordingSchemaBuilder([
            'activity_logs' => ['columns' => self::BASE_COLUMNS],
        ]);

        $operations = $schema->run($this->app, base_path(self::ACTIVITY_LOG_I18N));

        $commands = array_column(array_merge(...array_column($operations, 'commands')), 'name');
        $this->assertNotContains('dropColumn', $commands, '이미 없는 컬럼을 지우려 했습니다');
        $this->assertSame(
            ['description_key', 'description_params', 'changes'],
            array_merge(...array_column($operations, 'added')),
        );
        $this->assertSame(
            ['idx_activity_logs_loggable', 'idx_activity_logs_type_action_date'],
            array_column(array_merge(...array_column($operations, 'commands')), 'index'),
        );

        $schema->resetOperations();
        $this->assertSame([], $schema->run($this->app, base_path(self::ACTIVITY_LOG_I18N)), '두 번째 실행이 DDL 을 냈습니다');
    }

    /**
     * 활동 로그 i18n 마이그레이션의 `down()` 은 인덱스를 못 만들고 멈춘 설치본에서도 되돌릴 수 있다.
     *
     * 수정 전 실패 이유: `down()` 이 인덱스 2개와 컬럼 3개를 확인 없이 한 번에 지워, 인덱스 단계
     * 전에 멈춘 설치본에서 "Can't DROP index" 로 되돌리기 자체가 막힌다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects activity_log_i18n_down_survives_a_partial_up
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_i18n_down_survives_a_partial_up(): void
    {
        $schema = new RecordingSchemaBuilder([
            'activity_logs' => ['columns' => [...self::BASE_COLUMNS, 'description_key', 'description_params', 'changes']],
        ]);

        $operations = $schema->run($this->app, base_path(self::ACTIVITY_LOG_I18N), 'down');

        $commands = array_column(array_merge(...array_column($operations, 'commands')), 'name');
        $this->assertNotContains('dropIndex', $commands, '없는 인덱스를 지우려 했습니다');
        $this->assertContains('dropColumn', $commands);
        $this->assertTrue($schema->hasColumn('activity_logs', 'description'), '되돌린 뒤 description 이 복원되어야 합니다');
        $this->assertFalse($schema->hasColumn('activity_logs', 'description_key'));
    }

    /**
     * 조각 식별자 마이그레이션은 고유 키가 이미 있으면 다시 만들지 않고, 되돌리기도 고유 키가 있을 때만 지운다.
     *
     * 이 마이그레이션은 MySQL 에서 접두 길이 고유 키를 raw 문으로 만들고 백필 UPDATE 도 실제로 내므로
     * 기록기 위에서 돌리지 않고 소스를 읽는다(실행하면 공유 테스트 DB 에 DDL·DML 이 나간다).
     *
     * 수정 전 실패 이유: 고유 키 생성에 존재 확인이 없어, 고유 키까지 만들고 멈춘 설치본을 다시 돌리면
     * `Duplicate key name` 으로 매번 실패했고, 고유 키 전에 멈춘 설치본의 되돌리기는 없는 키를 지우려다 막혔다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects layout_fragment_key_unique_index_is_guarded_on_rerun_and_rollback
     *
     * @return void 반환값 없음
     */
    public function test_layout_fragment_key_unique_index_is_guarded_on_rerun_and_rollback(): void
    {
        $source = (string) file_get_contents(base_path(self::LAYOUT_FRAGMENT_KEY));
        $guard = "Schema::hasIndex('template_layout_extensions', 'uk_layout_extension_fragment')";

        $up = substr($source, (int) strpos($source, 'function up()'), (int) strpos($source, 'function down()') - (int) strpos($source, 'function up()'));
        $down = substr($source, (int) strpos($source, 'function down()'));

        $this->assertNotFalse(strpos($up, $guard), 'up() 이 고유 키 존재를 확인하지 않습니다');
        $this->assertLessThan(strpos($up, 'ADD UNIQUE'), strpos($up, $guard), '고유 키 존재 확인이 생성보다 뒤에 있습니다');
        $this->assertNotFalse(strpos($down, $guard), 'down() 이 고유 키 존재를 확인하지 않고 지웁니다');
    }
}
