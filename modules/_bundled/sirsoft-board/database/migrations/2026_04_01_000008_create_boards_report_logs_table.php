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
        // 신고 로그
        Schema::create('boards_report_logs', function (Blueprint $table) {
            $table->id()->comment('신고 로그 ID');
            $table->foreignId('report_id')->constrained('boards_reports')->cascadeOnDelete()->comment('케이스 ID (boards_reports.id)');
            $table->unsignedBigInteger('reporter_id')->nullable()->comment('신고자 ID (탈퇴 시 NULL)');
            $table->mediumText('snapshot')->nullable()->comment('신고 당시 게시물 스냅샷 (JSON: board_name, title, content, content_mode, author_name)');
            $table->string('reason_type', 50)->nullable()->comment('신고 사유 유형 (abuse, hate_speech, spam, copyright, privacy, misinformation, sexual, violence, other)');
            $table->text('reason_detail')->nullable()->comment('신고 상세 사유 (신고자 입력)');
            $table->text('metadata')->nullable()->comment('메타데이터 (IP, User Agent 등)');
            $table->timestamps();

            $table->unique(['report_id', 'reporter_id'], 'unique_report_log');
            $table->index('report_id', 'idx_report');
            $table->index('reporter_id', 'idx_reporter');

            $table->foreign('reporter_id')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('boards_report_logs', function (Blueprint $table) {
                $table->comment('게시판 신고 로그 (신고자별 기록)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards_report_logs')) {
            Schema::dropIfExists('boards_report_logs');
        }
    }
};
