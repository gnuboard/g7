<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 신고 케이스
        Schema::create('boards_reports', function (Blueprint $table) {
            $table->id()->comment('신고 케이스 ID');
            $table->unsignedBigInteger('board_id')->nullable()->comment('게시판 ID (게시판 삭제 시 NULL)');
            $table->string('target_type', 20)->comment('신고 대상 타입 (post, comment)');
            $table->unsignedBigInteger('target_id')->comment('신고 대상 ID (동적 테이블의 ID)');
            $table->unsignedBigInteger('author_id')->nullable()->comment('작성자 ID (작성자 삭제 시 NULL)');
            $table->string('status', 20)->default('pending')->comment('신고 상태 (pending, review, rejected, suspended)');
            $table->unsignedBigInteger('processed_by')->nullable()->comment('처리자 ID');
            $table->timestamp('processed_at')->nullable()->comment('처리 일시');
            $table->mediumText('process_histories')->nullable()->comment('처리 이력 배열 [{type, admin_id, admin_name, reason, reporter_count, created_at}]');
            $table->text('metadata')->nullable()->comment('메타데이터 (IP, User Agent 등)');
            $table->timestamp('last_reported_at')->nullable()->comment('마지막 신고 일시 — 목록 정렬용');
            $table->timestamp('last_activated_at')->nullable()->comment('케이스 재활성 일시 — 자동 블라인드 카운트 기준 (재신고 시 갱신)');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['board_id', 'target_type', 'target_id'], 'unique_report_case');
            $table->index('board_id', 'idx_board');
            $table->index(['board_id', 'target_type', 'target_id'], 'idx_target');
            $table->index('author_id', 'idx_author');
            $table->index('status', 'idx_status');
            $table->index('last_reported_at', 'idx_last_reported');

            $table->foreign('board_id')->references('id')->on('boards')->nullOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('processed_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('boards_reports', function (Blueprint $table) {
                $table->comment('게시판 신고 케이스 (게시글/댓글당 1행)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards_reports')) {
            Schema::dropIfExists('boards_reports');
        }
    }
};
