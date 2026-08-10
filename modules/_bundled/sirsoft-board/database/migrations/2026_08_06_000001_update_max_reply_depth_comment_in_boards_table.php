<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * boards.max_reply_depth 컬럼 comment 의 상한 표기를 실제 상한과 맞춥니다.
     *
     * 실제 허용 범위의 SSoT 는 `config/board.php` 의 `max_reply_depth_min=1` /
     * `max_reply_depth_max=10` 이며, 설정 화면과 검증 규칙이 이 값을 쓴다. 그런데 컬럼
     * comment 만 `(1~5)` 로 남아 있었고, API 레퍼런스 문서가 컬럼 comment 에서 자동
     * 생성되므로(`ColumnCommentResolver`) 계약 문서 네 곳이 실제보다 좁은 상한을
     * 공개하고 있었다 — 6~10 을 넣어도 저장되는데 문서는 5 까지라고 말한다.
     *
     * 데이터는 바꾸지 않는다. 타입·기본값·NULL 허용은 원 정의와 동일하게 유지하고
     * comment 만 교체한다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards')) {
            return;
        }

        $prefix = DB::getTablePrefix();

        DB::statement(
            "ALTER TABLE {$prefix}boards MODIFY COLUMN max_reply_depth "
            .'SMALLINT UNSIGNED NOT NULL DEFAULT 5 '
            ."COMMENT '답변글 최대 깊이 (1~10)'"
        );
    }

    /**
     * Reverse the migrations.
     *
     * comment 표기만 원복한다 (데이터·타입·기본값 무변경).
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards')) {
            return;
        }

        $prefix = DB::getTablePrefix();

        DB::statement(
            "ALTER TABLE {$prefix}boards MODIFY COLUMN max_reply_depth "
            .'SMALLINT UNSIGNED NOT NULL DEFAULT 5 '
            ."COMMENT '답변글 최대 깊이 (1~5)'"
        );
    }
};
