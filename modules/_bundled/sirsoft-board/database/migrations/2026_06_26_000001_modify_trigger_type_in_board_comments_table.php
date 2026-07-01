<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * board_comments.trigger_type enum 에 'cascade' 값을 추가합니다.
     * 게시글 삭제 시 함께 지워지는 댓글을 'cascade' 로 마킹하여, 게시글 복원 시
     * 사용자가 직접 지운 댓글('user')과 구분해 선별 복원하기 위함입니다.
     */
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        // enum 에 'cascade' 추가 (조치 주체: 게시글 삭제 연쇄)
        DB::statement(
            "ALTER TABLE {$prefix}board_comments MODIFY COLUMN trigger_type "
            ."ENUM('report', 'admin', 'system', 'auto_hide', 'user', 'cascade') "
            ."NOT NULL DEFAULT 'admin' "
            ."COMMENT '조치 주체 (report: 신고, admin: 관리자, system: 시스템, auto_hide: 자동 블라인드, user: 사용자 직접 삭제, cascade: 게시글 삭제 연쇄)'"
        );
    }

    /**
     * Reverse the migrations.
     *
     * enum 축소 전, 잔존하는 'cascade' 행을 'admin' 으로 치환해야 롤백이 깨지지 않습니다.
     * (enum 에 없는 값이 데이터에 남아 있으면 MODIFY COLUMN 이 실패)
     */
    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        // 잔존 cascade 행을 admin 으로 치환 (enum 축소 안전 처리)
        DB::table('board_comments')
            ->where('trigger_type', 'cascade')
            ->update(['trigger_type' => 'admin']);

        DB::statement(
            "ALTER TABLE {$prefix}board_comments MODIFY COLUMN trigger_type "
            ."ENUM('report', 'admin', 'system', 'auto_hide', 'user') "
            ."NOT NULL DEFAULT 'admin' "
            ."COMMENT '조치 주체 (report: 신고, admin: 관리자, system: 시스템, auto_hide: 자동 블라인드, user: 사용자 직접 삭제)'"
        );
    }
};
