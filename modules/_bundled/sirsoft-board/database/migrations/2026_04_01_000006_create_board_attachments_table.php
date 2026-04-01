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

        // 첨부파일
        Schema::create('board_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true)->comment('첨부파일 ID');
            $table->unsignedBigInteger('board_id')->default(0)->comment('게시판 ID (파티션 키, 0: 임시 업로드)');
            $table->primary(['id', 'board_id']);

            $table->unsignedBigInteger('post_id')->nullable()->comment('게시글 ID (임시 업로드 시 null)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (게시글 저장 후 null)');
            $table->string('hash', 12)->comment('URL용 고유 해시 (12자)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장된 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('local')->comment('스토리지 디스크 (local, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, application/pdf)');
            $table->unsignedBigInteger('size')->comment('파일 크기 (바이트)');
            $table->string('collection', 100)->default('default')->comment('첨부파일 컬렉션/그룹명');
            $table->unsignedInteger('order')->default(0)->comment('정렬 순서');
            $table->text('meta')->nullable()->comment('추가 메타데이터 JSON (썸네일 경로, 이미지 크기 등)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('업로더 ID');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['board_id', 'hash'], 'uq_board_hash');
            $table->index(['board_id', 'post_id'], 'idx_board_post');
            $table->index(['board_id', 'temp_key'], 'idx_board_temp_key');
            $table->index('collection', 'idx_collection');
            $table->index('deleted_at', 'idx_deleted_at');
            $table->index('hash', 'idx_hash');
        });

        DB::statement("ALTER TABLE {$prefix}board_attachments PARTITION BY LIST (board_id) (PARTITION p_default VALUES IN (0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_attachments')) {
            Schema::dropIfExists('board_attachments');
        }
    }
};
