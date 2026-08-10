<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게시글 목록 인덱스 말미에 기본키를 덧붙여 정렬을 완전히 덮습니다.
 *
 * 기존 `idx_board_posts_list_count` 는 [board_id, is_notice, parent_id, deleted_at, created_at]
 * 로 끝난다. 목록 정렬은 `created_at DESC, id DESC` 인데 인덱스가 created_at 에서 끝나므로
 * 동률 구간의 id 순서를 인덱스로 만들 수 없어 filesort 가 붙는다.
 *
 * 20만 행 계측(2026-08-01, MySQL 8.4.3)에서 지연 조인의 inner 쿼리가
 * `key=idx_board_posts_list_count ... Using index; Using filesort` 로 잡혔고, OFFSET 199,980
 * 에서 77.8ms 였다. inner 는 키 컬럼만 읽으므로 filesort 만 걷어내면 인덱스 순서 그대로
 * 끝난다(= `Using index` 단독).
 *
 * 같은 선행 컬럼을 가진 인덱스를 새로 만들면 기존 인덱스가 그대로 남아 쓰기 비용만 늘어나므로
 * **교체**한다. 뒤에 id 를 덧붙인 인덱스는 기존 인덱스가 답하던 조회를 모두 포함한다.
 *
 * `idx_board_posts_adjacent` 로는 대체되지 않는다 — 그 인덱스는 2번째가 `status` 인데
 * 목록 조회가 status 를 등치로 고정하지 않는 경우가 있어 그 지점에서 등치 사슬이 끊기고
 * 뒤의 created_at 을 정렬에 쓸 수 없다.
 *
 * 소프트 삭제 단독 인덱스(`g7_board_posts_deleted_at_index`) 제거는 다른 조회가 의존할 수
 * 있으므로 이번 범위에서 다루지 않는다.
 */
return new class extends Migration
{
    private const TABLE = 'board_posts';

    private const OLD_INDEX = 'idx_board_posts_list_count';

    private const NEW_INDEX = 'idx_board_posts_list_count_id';

    /** @var array<int, string> */
    private const OLD_COLUMNS = ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'created_at'];

    /** @var array<int, string> */
    private const NEW_COLUMNS = ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'created_at', 'id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            if (! in_array(self::NEW_INDEX, $existing, true)) {
                $table->index(self::NEW_COLUMNS, self::NEW_INDEX);
            }

            // 새 인덱스가 기존 인덱스의 상위집합이므로 남겨 두면 쓰기 비용만 늘어난다
            if (in_array(self::OLD_INDEX, $existing, true)) {
                $table->dropIndex(self::OLD_INDEX);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            if (! in_array(self::OLD_INDEX, $existing, true)) {
                $table->index(self::OLD_COLUMNS, self::OLD_INDEX);
            }

            if (in_array(self::NEW_INDEX, $existing, true)) {
                $table->dropIndex(self::NEW_INDEX);
            }
        });
    }
};
