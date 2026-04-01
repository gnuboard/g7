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

        // 게시글
        Schema::create('board_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true)->comment('게시글 ID');
            $table->unsignedBigInteger('board_id')->comment('게시판 ID (파티션 키)');
            $table->primary(['id', 'board_id']);

            $table->string('category', 50)->nullable()->index()->comment('분류');
            $table->string('title', 200)->comment('제목');
            $table->longText('content')->comment('내용');
            $table->string('content_mode', 10)->default('text')->comment('콘텐츠 모드: text(텍스트), html(HTML)');

            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('회원 ID');
            $table->string('author_name', 50)->nullable()->comment('작성자명 (비회원)');
            $table->string('password')->nullable()->comment('비밀번호 (비회원, bcrypt)');
            $table->string('ip_address', 45)->comment('IP 주소');

            $table->boolean('is_notice')->default(false)->comment('공지사항 여부 (1: 공지, 0: 일반)');
            $table->boolean('is_secret')->default(false)->comment('비밀글 여부 (1: 비밀, 0: 공개)');
            $table->enum('status', ['published', 'blinded', 'deleted'])->default('published')->comment('게시글 상태 (published: 게시됨, blinded: 블라인드 처리됨, deleted: 삭제됨)');
            $table->enum('trigger_type', ['report', 'admin', 'system', 'auto_hide', 'user'])->default('admin')->comment('조치 주체 (report: 신고, admin: 관리자, system: 시스템, auto_hide: 자동 블라인드, user: 사용자 직접 삭제)');
            $table->text('action_logs')->nullable()->comment('작업 이력 배열 (JSON)');

            $table->unsignedInteger('view_count')->default(0)->index()->comment('조회수');

            $table->unsignedBigInteger('parent_id')->nullable()->index()->comment('부모 게시글 ID (답글용)');
            $table->unsignedInteger('depth')->default(0)->comment('답글 깊이 (0: 원글, 1+: 답글)');

            $table->timestamps();
            $table->softDeletes();

            $table->index('deleted_at');
            $table->index(['board_id', 'created_at'], 'idx_board_created');
            $table->index(['board_id', 'status'], 'idx_board_status');
            $table->index(['board_id', 'is_notice'], 'idx_board_notice');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index(['user_id', 'view_count'], 'idx_user_views');
        });

        DB::statement("ALTER TABLE {$prefix}board_posts PARTITION BY LIST (board_id) (PARTITION p_default VALUES IN (0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_posts')) {
            Schema::dropIfExists('board_posts');
        }
    }
};
