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
        Schema::create('page_attachments', function (Blueprint $table) {
            $table->id()->comment('첨부파일 ID');
            $table->unsignedBigInteger('page_id')->nullable()->comment('페이지 ID (임시 업로드 시 null)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (페이지 저장 전 파일 그룹화)');
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장된 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('modules')->comment('스토리지 디스크 (modules, public, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, application/pdf)');
            $table->unsignedBigInteger('size')->comment('파일 크기 (바이트)');
            $table->string('collection', 100)->default('attachments')->comment('파일 컬렉션 (attachments)');
            $table->unsignedInteger('order')->default(0)->comment('정렬 순서');
            $table->text('meta')->nullable()->comment('추가 메타 정보 (JSON)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('업로더 ID');
            $table->timestamps();
            $table->softDeletes()->comment('소프트 삭제 일시');

            $table->index('page_id');
            $table->index('temp_key');
            $table->index(['page_id', 'order']);
            $table->index('deleted_at');

            $table->foreign('page_id')->references('id')->on('pages')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('page_attachments', function (Blueprint $table) {
                $table->comment('페이지 첨부파일');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('page_attachments')) {
            Schema::dropIfExists('page_attachments');
        }
    }
};
