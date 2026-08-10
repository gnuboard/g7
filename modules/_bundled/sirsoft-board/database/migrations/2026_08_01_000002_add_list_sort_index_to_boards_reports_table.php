<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 신고 목록의 정렬 색인을 추가합니다.
 *
 * 신고 관리 목록은 `deleted_at IS NULL` 로 좁힌 뒤 `created_at DESC, id DESC` 로 정렬하는데,
 * 그 순서를 덮는 색인이 없어 뒤쪽 페이지가 테이블 전체를 훑고 정렬했다. 지연 조인의 inner 가
 * 키 컬럼만 읽어도 인덱스 순서로 끝나지 못하면 깊은 OFFSET 개선 폭이 사라진다.
 *
 * 필요한 색인은 계측 프로파일 선언(filters / order / soft_delete)에서 도출한 것이며,
 * 게시판 모듈의 `ListIndexCoverageTest` 가 같은 규칙으로 전 목록을 검사한다.
 */
return new class extends Migration
{
    private const TABLE = 'boards_reports';

    private const INDEX_NAME = 'idx_boards_reports_deleted_created_id';

    /** @var array<int, string> */
    private const INDEX_COLUMNS = ['deleted_at', 'created_at', 'id'];

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
            if (! in_array(self::INDEX_NAME, $existing, true)) {
                $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
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
            if (in_array(self::INDEX_NAME, $existing, true)) {
                $table->dropIndex(self::INDEX_NAME);
            }
        });
    }
};
