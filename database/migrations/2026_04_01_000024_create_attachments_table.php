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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id()->comment('첨부파일 ID');
            $table->string('attachmentable_type', 255)->nullable()->comment('첨부 대상 모델 타입 (예: App\\Models\\Post)');
            $table->unsignedBigInteger('attachmentable_id')->nullable()->comment('첨부 대상 모델 ID');
            $table->enum('source_type', ['core', 'module', 'plugin'])->default('core')->comment('소스 타입: core(코어), module(모듈), plugin(플러그인)');
            $table->string('source_identifier', 255)->nullable()->comment('모듈/플러그인 식별자 (예: sirsoft-board)');
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장된 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('local')->comment('스토리지 디스크 (local, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, application/pdf)');
            $table->unsignedBigInteger('size')->comment('파일 크기 (바이트)');
            $table->string('collection', 100)->default('default')->comment('첨부파일 컬렉션/그룹명');
            $table->unsignedInteger('order')->default(0)->comment('정렬 순서');
            $table->text('meta')->nullable()->comment('추가 메타데이터 JSON');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachmentable_type', 'attachmentable_id'], 'idx_attachmentable');
            $table->index(['source_type', 'source_identifier'], 'idx_source');
            $table->index('collection', 'idx_collection');
            $table->index('deleted_at', 'idx_deleted_at');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('attachments', function (Blueprint $table) {
                $table->comment('첨부파일 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
