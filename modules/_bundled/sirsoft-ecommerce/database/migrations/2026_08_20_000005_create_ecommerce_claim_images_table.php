<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 첨부 이미지 테이블을 생성합니다.
 *
 * 교환/반품/환불 신청서가 공유하는 다형 부속물이다. 유형별 신청서 테이블을
 * 따로 두는 대신 첨부·추가결제 같은 공용 부속물만 다형 참조로 묶는다.
 * 저장은 StorageInterface 를 경유하며, 장수 상한은 설정 `claim.max_images` 가 정한다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 부분 실패 뒤 재실행이나 이미 만들어진 설치본에서 "Base table already exists" 로 멈추지 않게 한다.
        if (Schema::hasTable('ecommerce_claim_images')) {
            return;
        }

        Schema::create('ecommerce_claim_images', function (Blueprint $table) {
            $table->id()->comment('클레임 이미지 ID');
            $table->string('claimable_type', 20)->comment('첨부 대상 유형 (ClaimTypeEnum — exchange: 교환, return: 반품, refund: 환불 / pending: 신청 전 미귀속 임시값)');
            $table->unsignedBigInteger('claimable_id')->comment('첨부 대상 ID');
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');
            $table->string('disk', 50)->default('public')->comment('스토리지 디스크 (local, public, s3 등)');
            $table->string('path', 500)->comment('저장 경로 (디스크 기준 상대 경로)');
            $table->string('original_name', 255)->comment('원본 파일명');
            $table->string('mime_type', 100)->comment('MIME 타입 (예: image/jpeg, image/webp)');
            $table->unsignedBigInteger('size')->comment('파일 크기 (바이트)');
            $table->unsignedInteger('width')->nullable()->comment('이미지 너비 (px)');
            $table->unsignedInteger('height')->nullable()->comment('이미지 높이 (px)');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->foreignId('created_by')->nullable()->comment('업로더 ID (비회원이면 NULL)')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            // 첨부는 언제나 귀속 대상으로 먼저 좁힌 뒤 sort_order 로 정렬한다
            // (`ClaimImageRepository` 의 조회 전건). sort_order 단독 인덱스는 그 순서에서
            // 선두가 될 수 없어 쓰이지 않으므로 두지 않고, 정렬까지 복합 인덱스가 받는다.
            $table->index(['claimable_type', 'claimable_id', 'sort_order'], 'ecommerce_claim_images_claimable_index');

            $table->comment('클레임 첨부 이미지');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_claim_images')) {
            Schema::dropIfExists('ecommerce_claim_images');
        }
    }
};
