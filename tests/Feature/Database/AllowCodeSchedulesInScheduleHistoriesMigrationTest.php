<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 실행 이력 표를 코드 예약까지 넓히는 마이그레이션(2026_08_31_000001)의 재실행 가드 판정 테스트.
 *
 * 이 마이그레이션의 인덱스 존재 판정은 표 이름에 접두사를 직접 붙여 Schema 빌더에 넘겼다. 빌더도
 * 접두사를 붙이므로 `g7_g7_schedule_histories` 를 조회해 판정이 언제나 거짓이었고, 그래서 down() 은
 * 인덱스를 못 지우고 다음 up() 은 `Duplicate key name` 으로 죽었다. 여기서는 접두사가 있는 테스트
 * 환경(.env.testing DB_PREFIX=g7_)에서 판정 메서드가 이미 있는 인덱스·외래키를 참으로 보는지 단언한다.
 *
 * 판정 대상 인덱스·외래키는 이 마이그레이션을 거친 스키마에만 있으므로 RefreshDatabase 로 스키마를
 * 맞춘다. 시험 본문은 DDL 을 돌리지 않는다 — 적용이 끝난 스키마에 판정 메서드만 불러 본다.
 */
#[Group('core')]
#[Group('database')]
class AllowCodeSchedulesInScheduleHistoriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 접두사 환경에서 인덱스 존재 판정이 실제 인덱스를 찾는다.
     *
     * @scenario entry_point=cli
     *
     * @effects schedule_history_migration_guard_sees_prefixed_index
     *
     * @return void 반환값 없음
     */
    public function test_index_exists_sees_index_under_table_prefix(): void
    {
        $this->assertNotSame('', DB::getTablePrefix(), '이 단언은 접두사가 있는 환경이 전제다');
        $this->assertTrue(Schema::hasIndex('schedule_histories', 'idx_source_started_at'), '적용된 스키마에 인덱스가 있어야 한다');

        $this->assertTrue($this->callGuard('indexExists', 'idx_source_started_at'));
        $this->assertFalse($this->callGuard('indexExists', 'idx_not_existing_on_schedule_histories'));
    }

    /**
     * 접두사 환경에서 schedule_id 외래키 존재 판정이 실제 제약을 찾는다.
     *
     * @scenario entry_point=cli
     *
     * @effects schedule_history_migration_guard_sees_schedule_foreign_key
     *
     * @return void 반환값 없음
     */
    public function test_schedule_foreign_key_exists_sees_constraint_under_table_prefix(): void
    {
        $this->assertTrue($this->callGuard('scheduleForeignKeyExists'));
    }

    /**
     * 마이그레이션 인스턴스의 비공개 판정 메서드를 부릅니다.
     *
     * @param  string  $method  메서드 이름
     * @param  mixed  ...$args  인자
     * @return bool 판정 결과
     */
    private function callGuard(string $method, mixed ...$args): bool
    {
        $migration = require base_path('database/migrations/2026_08_31_000001_allow_code_schedules_in_schedule_histories_table.php');

        $reflection = new ReflectionMethod($migration, $method);

        return (bool) $reflection->invoke($migration, ...$args);
    }
}
