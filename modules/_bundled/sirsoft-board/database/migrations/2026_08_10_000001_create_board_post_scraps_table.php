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
     * 게시글 스크랩(북마크) 테이블.
     * post_id 는 댓글·신고와 동일하게 FK 없이 앱 계층에서 무결성을 관리한다
     * (board_posts 는 파티션 제거 이력이 있어 board 종속 테이블은 FK 를 걸지 않는 관례).
     * board_id nullOnDelete 로 게시판 삭제 후에도 레코드를 보존해
     * 유저가 마이페이지에서 직접 정리할 수 있게 한다.
     */
    public function up(): void
    {
        // 중단된 업데이트를 다시 실행해도 이미 만든 표에서 멈추지 않는다
        if (Schema::hasTable('board_post_scraps')) {
            return;
        }

        Schema::create('board_post_scraps', function (Blueprint $table) {
            $table->id()->comment('스크랩 ID');
            $table->unsignedBigInteger('user_id')->comment('스크랩한 회원 ID');
            $table->unsignedBigInteger('post_id')->comment('게시글 ID (FK 없음 — 앱 계층 무결성 관리)');
            $table->unsignedBigInteger('board_id')->nullable()->comment('게시판 ID (게시판 삭제 시 NULL)');
            $table->timestamps();

            $table->unique(['user_id', 'post_id'], 'unique_user_post_scrap');
            $table->index('post_id', 'idx_post');
            $table->index(['user_id', 'created_at'], 'idx_user_created');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('boards')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('board_post_scraps', function (Blueprint $table) {
                $table->comment('게시글 스크랩 (회원-게시글당 1행)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_post_scraps')) {
            Schema::dropIfExists('board_post_scraps');
        }
    }
};
