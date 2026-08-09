<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 게시판 반응 이력 테이블 생성
     *
     * 사용자당 대상(게시글)에 1행. 등록=INSERT, 전환=UPDATE, 해제=DELETE.
     * 신고(boards_reports)처럼 target_type/target_id 폴리모픽 구조를 따르되,
     * 케이스/로그 2테이블로 나누지 않고 1테이블로 처리한다 (반응엔 처리 상태 개념 없음).
     */
    public function up(): void
    {
        Schema::create('board_reactions', function (Blueprint $table) {
            $table->id()->comment('반응 이력 ID');
            $table->unsignedBigInteger('user_id')->comment('반응한 사용자 ID');
            $table->string('target_type', 20)->comment('반응 대상 타입 (현재 post, 향후 comment 확장 가능)');
            $table->unsignedBigInteger('target_id')->comment('반응 대상 ID (동적 테이블 ID, FK 없이 앱 레벨 무결성 관리)');
            $table->unsignedBigInteger('reaction_type_id')->comment('반응 유형 ID');
            $table->unsignedBigInteger('board_id')->nullable()->comment('게시판 ID (게시판 삭제 시 NULL)');
            $table->timestamps();

            $table->unique(['user_id', 'target_type', 'target_id'], 'unique_user_target_reaction');
            $table->index(['target_type', 'target_id'], 'idx_reaction_target');
            $table->index('reaction_type_id', 'idx_reaction_type');
            $table->index('board_id', 'idx_reaction_board');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reaction_type_id')->references('id')->on('board_reaction_types')->restrictOnDelete();
            $table->foreign('board_id')->references('id')->on('boards')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('board_reactions', function (Blueprint $table) {
                $table->comment('게시판 반응 이력 (사용자+대상당 1행)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_reactions');
    }
};
