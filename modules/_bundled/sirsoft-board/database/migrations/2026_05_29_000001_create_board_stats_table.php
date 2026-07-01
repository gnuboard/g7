<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 대시보드용 일별 게시물/댓글 집계 테이블.
     * 스케쥴러(sirsoft-board:aggregate-stats)가 하루 1행씩 upsert 하며,
     * 대시보드 API 는 원본 풀스캔 없이 이 테이블만 읽는다.
     */
    public function up(): void
    {
        Schema::create('board_stats', function (Blueprint $table) {
            $table->id()->comment('집계 행 ID');
            $table->date('date')->unique()->comment('집계 기준 날짜 (하루 1행, 멱등 upsert 키)');
            $table->unsignedInteger('post_count')->default(0)->comment('해당 날짜 작성 게시글 수 (deleted_at IS NULL)');
            $table->unsignedInteger('comment_count')->default(0)->comment('해당 날짜 작성 댓글 수 (deleted_at IS NULL)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_stats');
    }
};
