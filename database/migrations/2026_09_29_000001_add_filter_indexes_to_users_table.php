<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 테이블에 회원 목록 필터·정렬용 인덱스를 추가합니다 (#705).
 *
 * - idx_users_status_created_at : (status, created_at) — 기본 필터(계정 상태 + 가입일)와 기본 정렬
 * - idx_users_last_login_at     : 최근 로그인 범위·정렬
 * - idx_users_dormant_at        : 휴면 전환일 범위
 * - idx_users_withdrawn_at      : 상태 변경일(탈퇴) 범위
 * - idx_users_blocked_at        : 상태 변경일(차단) 범위
 * - idx_users_name              : 이름 정렬
 *
 * 넣지 않는 것: email_verified_at·identity_verified_at·language·locked_until 은 카디널리티가 낮아
 * 단독 인덱스 이득이 없고 늘 다른 조건과 함께 쓰인다. 선두 와일드카드 LIKE 는 어떤 인덱스도 타지 않는다.
 *
 * database-guide.md 「정렬을 덮는 복합 인덱스의 선행 컬럼」 과의 관계:
 * - `(status, created_at)` 은 필터 없는 기본 진입의 정렬을 맡지 않는다 — 그것은 기존 `idx_users_created_at`
 *   이 덮는다. 이 복합은 상태 하나를 고른 목록의 가입일 정렬을 덮는 보조다.
 * - 대부분 NULL 인 `dormant_at`·`withdrawn_at`·`blocked_at` 단독 인덱스가 해로운 경우는 「늘 `IS NULL`
 *   이 붙는 쿼리」다. 이 칸들은 드문 non-NULL 값을 범위·`IS NOT NULL` 로 고를 때만 쓰이고(선택도 높음),
 *   `IS NULL` 로 상시 조회하는 운영 쿼리는 없다(`User::scopeActive` 는 호출처 0 — 2026-09-30 확인).
 *   그런 쿼리를 새로 만들면 이 인덱스가 index_merge 후보가 되는지 EXPLAIN 으로 확인한다.
 *
 * 기존 `status` 단일 인덱스는 지우지 않는다 — 다른 쿼리가 쓴다. `status` 컬럼에 `->index()` 를 다시
 * 부르지 않는 이유는 'Duplicate key name' 선례다(2026_09_21_000001_add_dormant_at_to_users_table.php).
 * 재실행·부분 실패에 견디도록 이름으로 존재를 검사한 뒤 추가한다(add_indexes_to_users_table 선례).
 *
 * @since 7.1.0
 */
return new class extends Migration
{
    /**
     * 인덱스 이름 => 컬럼 목록
     *
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'idx_users_status_created_at' => ['status', 'created_at'],
        'idx_users_last_login_at' => ['last_login_at'],
        'idx_users_dormant_at' => ['dormant_at'],
        'idx_users_withdrawn_at' => ['withdrawn_at'],
        'idx_users_blocked_at' => ['blocked_at'],
        'idx_users_name' => ['name'],
    ];

    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $existing = array_column(Schema::getIndexes('users'), 'name');

        foreach ($this->indexes as $name => $columns) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn('users', $column)) {
                    continue 2;
                }
            }

            Schema::table('users', function (Blueprint $table) use ($name, $columns) {
                $table->index($columns, $name);
            });
        }
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $existing = array_column(Schema::getIndexes('users'), 'name');

        foreach (array_keys($this->indexes) as $name) {
            if (! in_array($name, $existing, true)) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }
};
