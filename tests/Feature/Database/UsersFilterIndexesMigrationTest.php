<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 회원 목록 필터 인덱스 마이그레이션 왕복 테스트 (#705 D10)
 *
 * 필터 12축 중 범위·정렬로 자주 쓰이는 칸에 인덱스 6개를 더했다. 이 마이그레이션은
 * 이름으로 존재를 검사한 뒤 추가하므로 몇 번을 돌려도 같은 결과여야 하고, down 은 자기가
 * 만든 인덱스만 지워야 한다(기존 `status` 단일 인덱스·`idx_users_created_*` 는 다른 쿼리가 쓴다).
 *
 * DDL 은 MySQL 에서 암묵 커밋을 일으켜 트랜잭션 격리와 섞을 수 없으므로 격리 트레이트를 쓰지
 * 않는다. 대신 끝에서 반드시 up 상태로 되돌려 테스트 DB 스키마를 원래대로 남긴다.
 */
#[Group('core')]
#[Group('database')]
class UsersFilterIndexesMigrationTest extends TestCase
{
    /**
     * 이 마이그레이션이 만드는 인덱스 이름
     *
     * @var array<int, string>
     */
    private const OWN_INDEXES = [
        'idx_users_status_created_at',
        'idx_users_last_login_at',
        'idx_users_dormant_at',
        'idx_users_withdrawn_at',
        'idx_users_blocked_at',
        'idx_users_name',
    ];

    /**
     * up 은 멱등이고, down 은 자기 인덱스만 지우며, 다시 up 하면 원래대로 돌아온다.
     *
     * @scenario filter_axis=created_at, outcome=narrows
     *
     * @effects users_filter_indexes_idempotent_up
     *
     * @return void 반환값 없음
     */
    public function test_up_down_up_round_trip(): void
    {
        $migration = require base_path('database/migrations/2026_09_29_000001_add_filter_indexes_to_users_table.php');

        try {
            $migration->up();
            $migration->up(); // 두 번 불러도 'Duplicate key name' 없이 지나가야 한다.

            $afterUp = $this->indexNames();
            foreach (self::OWN_INDEXES as $name) {
                $this->assertContains($name, $afterUp, "up 뒤 {$name} 존재");
            }
            $foreign = array_values(array_diff($afterUp, self::OWN_INDEXES));

            $migration->down();

            $afterDown = $this->indexNames();
            foreach (self::OWN_INDEXES as $name) {
                $this->assertNotContains($name, $afterDown, "down 뒤 {$name} 제거");
            }
            // 다른 인덱스는 하나도 건드리지 않는다.
            $this->assertSame($foreign, array_values(array_diff($afterDown, self::OWN_INDEXES)));
        } finally {
            $migration->up();
        }

        $this->assertEmpty(array_diff(self::OWN_INDEXES, $this->indexNames()));
    }

    /**
     * (status, created_at) 복합 인덱스의 컬럼 순서가 기본 필터 조합과 같다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects users_filter_indexes_idempotent_up
     *
     * @return void 반환값 없음
     */
    public function test_status_created_at_column_order(): void
    {
        $index = collect(Schema::getIndexes('users'))->firstWhere('name', 'idx_users_status_created_at');

        $this->assertNotNull($index);
        $this->assertSame(['status', 'created_at'], $index['columns']);
    }

    /**
     * users 테이블의 인덱스 이름 목록을 정렬해 돌려줍니다.
     *
     * @return array<int, string> 인덱스 이름
     */
    private function indexNames(): array
    {
        $names = array_column(Schema::getIndexes('users'), 'name');
        sort($names);

        return $names;
    }
}
