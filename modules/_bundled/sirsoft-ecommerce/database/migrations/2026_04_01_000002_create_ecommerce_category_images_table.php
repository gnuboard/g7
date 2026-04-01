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
        Schema::create('ecommerce_category_images', function (Blueprint $table) {
            $table->id()->comment('이미지 ID');
            $table->unsignedBigInteger('category_id')->nullable()->comment('카테고리 ID (null이면 임시 업로드)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (카테고리 생성 전 업로드용)');
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장된 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('local')->comment('스토리지 디스크 (local, public, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, image/webp)');
            $table->unsignedBigInteger('file_size')->comment('파일 크기 (바이트)');
            $table->unsignedInteger('width')->nullable()->comment('이미지 너비 (px)');
            $table->unsignedInteger('height')->nullable()->comment('이미지 높이 (px)');
            $table->text('alt_text')->nullable()->comment('대체 텍스트 (다국어 JSON: {ko: "...", en: "..."})');
            $table->string('collection', 100)->default('main')->comment('이미지 컬렉션: main(메인)');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->comment('소프트 삭제 일시');

            $table->index('category_id');
            $table->index(['category_id', 'sort_order']);
            $table->index('temp_key');
            $table->index('deleted_at');
            $table->foreign('category_id')->references('id')->on('ecommerce_categories')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_category_images` COMMENT '카테고리 이미지 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_category_images')) {
            Schema::dropIfExists('ecommerce_category_images');
        }
    }
};
