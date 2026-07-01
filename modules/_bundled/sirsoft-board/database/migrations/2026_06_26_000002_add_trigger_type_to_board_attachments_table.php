<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * board_attachments 에 trigger_type enum 컬럼을 추가합니다.
     * 첨부에는 조치 주체 컬럼이 없어, 게시글 삭제 연쇄로 지워진 첨부('cascade')와
     * 사용자가 직접 지운 첨부('user')를 구분할 수 없었습니다. 게시글 복원 시
     * 선별 복원을 위해 댓글과 동일한 enum 값 세트를 부여합니다.
     */
    public function up(): void
    {
        Schema::table('board_attachments', function (Blueprint $table) {
            $table->enum('trigger_type', ['report', 'admin', 'system', 'auto_hide', 'user', 'cascade'])
                ->default('admin')
                ->after('created_by')
                ->comment('삭제 조치 주체 (admin: 관리자, user: 사용자 직접 삭제, cascade: 게시글 삭제 연쇄)');

            $table->index(['board_id', 'post_id', 'trigger_type'], 'idx_board_post_trigger');
        });
    }

    /**
     * Reverse the migrations.
     *
     * 컬럼/인덱스 존재 확인 후 삭제 (방어적 코딩).
     */
    public function down(): void
    {
        Schema::table('board_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('board_attachments', 'trigger_type')) {
                $table->dropIndex('idx_board_post_trigger');
                $table->dropColumn('trigger_type');
            }
        });
    }
};
