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
        Schema::create('ecommerce_product_review_images', function (Blueprint $table) {
            $table->id()->comment('리뷰 이미지 ID');
            $table->unsignedBigInteger('review_id')->comment('리뷰 ID');

            // 파일 식별 (URL용 해시)
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');

            // 파일 정보
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장된 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('public')->comment('스토리지 디스크 (local, public, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('url', 500)->nullable()->comment('외부 접근 가능 URL (CDN 등)');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, image/webp)');
            $table->unsignedBigInteger('file_size')->comment('파일 크기 (바이트)');

            // 이미지 메타 정보
            $table->unsignedInteger('width')->nullable()->comment('이미지 너비 (px)');
            $table->unsignedInteger('height')->nullable()->comment('이미지 높이 (px)');

            // 대체 텍스트 (다국어)
            $table->text('alt_text')->nullable()->comment('대체 텍스트 (다국어 JSON: {ko: "...", en: "..."})');

            // 이미지 분류 및 상태
            $table->string('collection', 100)->default('review')
                ->comment('이미지 컬렉션: review(리뷰)');
            $table->boolean('is_thumbnail')->default(false)->comment('대표 이미지 여부');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');

            // 감사 필드
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('업로더 ID');
            $table->timestamps();
            $table->softDeletes()->comment('소프트 삭제 일시');

            // 인덱스
            $table->index('review_id');
            $table->index('deleted_at');

            $table->foreign('review_id')
                ->references('id')
                ->on('ecommerce_product_reviews')
                ->onDelete('cascade');
        });

        // 테이블 COMMENT 추가 (MySQL 전용)
        if (DB::getDriverName() == 'mysql') {
            Schema::table('ecommerce_product_review_images', function (Blueprint $table) {
                $table->comment('상품 리뷰 이미지 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_product_review_images');
    }
};
