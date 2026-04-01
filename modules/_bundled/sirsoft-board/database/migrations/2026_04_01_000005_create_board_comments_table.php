<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL/MariaDB 전용: PARTITION BY LIST (board_id) 파티션 적용
     * 파티션 테이블에는 FK 설정 불가 — 앱 계층에서 무결성 처리
     */
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        // 댓글
        Schema::create('board_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true)->comment('댓글 ID');
            $table->unsignedBigInteger('board_id')->comment('게시판 ID (파티션 키)');
            $table->primary(['id', 'board_id']);

            $table->unsignedBigInteger('post_id')->comment('게시글 ID');
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('작성자 ID (회원)');
            $table->unsignedBigInteger('parent_id')->nullable()->index()->comment('부모 댓글 ID (답글용)');
            $table->string('author_name', 50)->nullable()->comment('작성자명 (비회원용)');
            $table->string('password')->nullable()->comment('비밀번호 (비회원용, 해시 저장)');
            $table->text('content')->comment('댓글 내용');
            $table->boolean('is_secret')->default(false)->comment('비밀 댓글 여부 (1: 비밀, 0: 공개)');
            $table->enum('status', ['published', 'blinded', 'deleted'])->default('published')->comment('댓글 상태 (published: 게시됨, blinded: 블라인드 처리됨, deleted: 삭제됨)');
            $table->enum('trigger_type', ['report', 'admin', 'system', 'auto_hide', 'user'])->default('admin')->comment('조치 주체 (report: 신고, admin: 관리자, system: 시스템, auto_hide: 자동 블라인드, user: 사용자 직접 삭제)');
            $table->text('action_logs')->nullable()->comment('작업 이력 배열 (JSON)');
            $table->unsignedTinyInteger('depth')->default(0)->comment('댓글 깊이 (0: 댓글, 1: 답글)');
            $table->string('ip_address', 45)->nullable()->comment('작성자 IP');

            $table->timestamps();
            $table->softDeletes();

            $table->index('deleted_at');
            $table->index(['board_id', 'post_id'], 'idx_board_post');
            $table->index(['board_id', 'status'], 'idx_board_status');
            $table->index(['board_id', 'created_at'], 'idx_board_created');
            $table->index(['user_id', 'post_id'], 'idx_user_post');
            $table->index(['user_id', 'created_at'], 'idx_user_created_at');
        });

        DB::statement("ALTER TABLE {$prefix}board_comments PARTITION BY LIST (board_id) (PARTITION p_default VALUES IN (0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_comments')) {
            Schema::dropIfExists('board_comments');
        }
    }
};
