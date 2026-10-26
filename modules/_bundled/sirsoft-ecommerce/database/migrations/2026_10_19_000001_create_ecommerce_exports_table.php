<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 상품·주문·마일리지 목록에서 요청한 내보내기(CSV 등) 작업 1건이 1행이다. 요청은 큐에서 처리되므로
     * 대기 → 진행중 → 완료/실패 → 만료의 수명을 갖고, 만들어진 파일의 위치·크기·해시와 요청 당시 조건을
     * 함께 남긴다. 삭제는 소프트 삭제다 — 파일만 지우고 다운로드 이력(감사 근거)은 보존한다.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_exports')) {
            return;
        }

        Schema::create('ecommerce_exports', function (Blueprint $table) {
            $table->id()->comment('내보내기 작업 ID');
            $table->uuid('uuid')->unique()->comment('외부 노출 식별자 (저장 경로에도 사용)');
            $table->string('type', 50)->comment('내보내기 유형 키 (orders=주문내역, products=상품내역, mileage=마일리지 내역, 확장 유형 키)');
            $table->string('format', 30)->comment('파일 형식 키 (csv, 확장 형식 키)');
            $table->string('status', 20)->default('pending')->comment('진행 상태 (ExportStatusEnum: pending=대기, processing=진행중, completed=완료, failed=실패, expired=만료)');
            $table->foreignId('requested_by')->nullable()->comment('요청자 회원 ID (회원 삭제 시 NULL)')->constrained('users')->nullOnDelete();
            $table->string('requester_name_snapshot', 100)->nullable()->comment('요청자 표시명 스냅샷 (탈퇴 뒤에도 표시)');
            $table->json('options')->comment('모달 입력 전체 (필드·행 단위·범위·기간·다국어 형식·합계·요청 화면 컬럼)');
            $table->json('filters')->nullable()->comment('「현재 검색조건」 범위의 검증된 필터 스냅샷');
            $table->json('selected_ids')->nullable()->comment('「선택한 행」 범위의 대상 ID 목록 (최대 1000)');
            $table->json('context')->nullable()->comment('요청 시점 실행 컨텍스트 스냅샷 (요청자·로케일·IP·경로 — 큐 작업에서 복원)');
            $table->boolean('contains_personal_data')->default(false)->comment('개인정보 필드 포함 여부 (1=포함, 0=미포함)');
            $table->string('reason', 500)->nullable()->comment('다운로드 사유 (개인정보 포함 요청에서 필수)');
            $table->string('idempotency_key', 64)->nullable()->comment('중복 요청 방지 키 (같은 요청자·같은 키는 기존 작업 반환)');
            $table->json('warnings')->nullable()->comment('요청 시점 경고 코드 목록 (queue_sync=큐 미설정, storage_public=공개 저장소)');
            $table->string('locale', 10)->nullable()->comment('요청자 로케일 (헤더·라벨 언어)');
            $table->string('timezone', 64)->nullable()->comment('일시 표기 시간대');
            $table->string('format_version', 20)->nullable()->comment('파일 형식 작성기 버전');
            $table->string('file_name', 255)->nullable()->comment('내려받을 때의 파일명');
            $table->string('file_path', 255)->nullable()->comment('저장소 상대 경로 (uuid 기반, 화면·API 에 노출하지 않음)');
            $table->unsignedBigInteger('file_size')->nullable()->comment('파일 크기 (바이트)');
            $table->char('checksum_sha256', 64)->nullable()->comment('완성 파일 SHA-256');
            $table->unsignedInteger('total_rows')->nullable()->comment('대상 행 수 (시작 시 산정)');
            $table->unsignedInteger('processed_rows')->default(0)->comment('처리한 행 수 (진행률 분자)');
            $table->unsignedInteger('download_count')->default(0)->comment('다운로드 횟수 캐시 (원장은 ecommerce_export_downloads)');
            $table->string('error_code', 100)->nullable()->comment('실패 사유 키 (예외 원문은 저장하지 않음)');
            $table->timestamp('requested_at')->nullable()->comment('요청 시각');
            $table->timestamp('cutoff_at')->nullable()->comment('기준 시각 — 기준일이 이 시각 이후인 행은 제외');
            $table->timestamp('started_at')->nullable()->comment('처리 시작 시각');
            $table->timestamp('completed_at')->nullable()->comment('완료 시각');
            $table->timestamp('expires_at')->nullable()->comment('파일 만료 예정 시각 (완료 시각 + 보관 기간)');
            $table->timestamps();
            $table->softDeletes()->comment('삭제 시각 (파일은 삭제, 기록·다운로드 이력은 보존)');

            $table->index(['status', 'requested_at'], 'ecommerce_exports_status_requested_index');
            $table->index(['requested_by', 'status'], 'ecommerce_exports_requested_by_status_index');
            $table->index(['type', 'requested_at'], 'ecommerce_exports_type_requested_index');
            $table->index('expires_at', 'ecommerce_exports_expires_index');
            $table->unique(['requested_by', 'idempotency_key'], 'ecommerce_exports_requested_by_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_exports');
    }
};
