<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 이커머스 범용 첨부 표를 만듭니다 (#887).
 *
 * 게시판 `board_attachments`·페이지 `page_attachments` 와 같은 모양의 이 모듈 첨부 표입니다. 행은 `collection` 으로 쓰임새를
 * 나누고(상품 설명·공통 정보 편집기 본문 미디어는 `content_media`), 소유자는 `attachmentable_type`(소유 모델의 morph 값)·
 * `attachmentable_id` 입니다. 저장 전 임시 행은 `attachmentable_id` null + `temp_key` 이고 소유 종류(`attachmentable_type`)는
 * 처음부터 가집니다. `path` 는 그 컬렉션 저장소 카테고리(본문 미디어는 `content-media`) 기준 상대 경로입니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_attachments')) {
            return;
        }

        Schema::create('ecommerce_attachments', function (Blueprint $table) {
            $table->id()->comment('첨부 ID');
            $table->string('attachmentable_type', 255)->comment('소유 모델 morph 값 (상품·상품 공통 정보 등, 임시 행도 가짐)');
            $table->unsignedBigInteger('attachmentable_id')->nullable()->comment('소유자 ID (null: 저장 전 임시 업로드)');
            $table->string('collection', 100)->default('default')->comment('컬렉션 (content_media: 편집기 본문 미디어)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (저장 전 편집 화면이 발급, 연결 뒤 null)');
            $table->string('hash', 12)->unique()->comment('서빙 주소 해시 (12자)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장 파일명 (UUID 기반)');
            $table->string('disk', 50)->default('modules')->comment('저장 디스크 (modules, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (컬렉션 저장소 카테고리 기준 상대 경로)');
            $table->string('mime_type', 100)->comment('내용으로 판정한 MIME');
            $table->unsignedBigInteger('size')->default(0)->comment('파일 크기 (바이트)');
            $table->json('meta')->nullable()->comment('부가 정보 (이미지 가로·세로 등)');
            $table->unsignedInteger('order')->default(0)->comment('본문 안 순서 (1부터, 임시 행 0)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('올린 사용자 ID');
            $table->timestamps();
            $table->softDeletes()->comment('삭제 시각 (본문에서 빠진 미디어 — 보존기간 뒤 정리)');

            $table->index(['attachmentable_type', 'attachmentable_id', 'collection'], 'idx_ecatt_owner');
            $table->index(['collection', 'temp_key'], 'idx_ecatt_temp');
            $table->index('deleted_at', 'idx_ecatt_deleted_at');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('ecommerce_attachments', function (Blueprint $table) {
                $table->comment('이커머스 첨부 (본문 미디어 등)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_attachments');
    }
};
