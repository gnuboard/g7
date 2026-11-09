<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이커머스 본문 미디어 테이블을 만듭니다 (#883 4단계).
 *
 * 상품 설명·상품 공통 정보 편집기 본문에 넣는 이미지·동영상·오디오입니다. 상품 이미지(`ecommerce_product_images`)를 쓰지 않는
 * 이유는 동영상에 맞지 않는 크기·썸네일 의미가 있고 공통 정보에는 첨부 저장소가 없기 때문입니다. 행은 저장 전까지 임시
 * (`owner_id` null + `temp_key`)이고, 저장 때 본문이 참조한 것만 소유자에 잇습니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_content_media')) {
            return;
        }

        Schema::create('ecommerce_content_media', function (Blueprint $table) {
            $table->id()->comment('본문 미디어 ID');
            $table->string('hash', 12)->unique()->comment('서빙 주소 해시 (12자)');
            $table->string('owner_type', 20)->comment('소유 종류 (product: 상품 설명, common_info: 상품 공통 정보)');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('소유자 ID (null: 저장 전 임시)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (저장 전 편집 화면이 발급)');
            $table->string('disk', 50)->comment('저장 디스크');
            $table->string('path', 500)->comment('저장 경로 (카테고리 기준 상대 경로)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장 파일명');
            $table->string('mime_type', 100)->comment('내용으로 판정한 MIME');
            $table->unsignedBigInteger('size')->default(0)->comment('파일 크기 (바이트)');
            $table->string('kind', 10)->comment('종류 (image·video·audio)');
            $table->json('meta')->nullable()->comment('부가 정보 (이미지 가로·세로 등)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('올린 사용자 ID');
            $table->timestamps();
            $table->softDeletes()->comment('삭제 시각 (본문에서 빠진 미디어)');

            $table->index(['owner_type', 'owner_id'], 'idx_ecm_owner');
            $table->index(['temp_key', 'owner_id'], 'idx_ecm_temp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_content_media');
    }
};
