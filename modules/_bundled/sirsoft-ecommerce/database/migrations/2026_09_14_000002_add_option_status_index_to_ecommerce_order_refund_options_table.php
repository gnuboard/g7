<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 환불 신청상품의 상태 컬럼에 인덱스를 추가하고 주석을 정정합니다.
 *
 * ## 왜 지금인가
 *
 * 상품 단위 전이가 환불까지 열리면서 `option_status` 가 **조회 조건**이 됐다. 수량 잠금 집계
 * (`OrderRefundRepository::sumActiveDirectQuantityByOrderOption()`)가 종결 상태를 제외하려고
 * 이 컬럼으로 거르고, 금액 재계산도 상태로 행을 가른다. 교환·반품 테이블에는 같은 인덱스가
 * 처음부터 있고(`ecommerce_order_rt_opts_status_index`) 환불만 빠져 있었다 — 신청상품이
 * 쌓일수록 그 차이는 "환불 화면만 느려진다" 로 나타난다.
 *
 * ## 주석 정정
 *
 * 생성 마이그레이션의 주석이 상태 6종만 적고 `withdrawn` 을 빠뜨렸다. 주석은 이 컬럼을 읽는
 * 사람이 보는 유일한 어휘 목록이라, 빠진 값은 "없는 상태" 로 읽힌다. 생성 마이그레이션 본문을
 * 고치면 **이미 적용된 데이터베이스에는 반영되지 않으므로** 여기서 실제 컬럼 주석을 바꾼다.
 */
return new class extends Migration
{
    /** 대상 테이블. */
    private const TABLE = 'ecommerce_order_refund_options';

    /** 인덱스 이름 — 교환·반품과 같은 규칙(`{테이블 약칭}_status_index`). */
    private const INDEX = 'ecommerce_order_refund_opts_status_index';

    /** 정정된 주석 — `RefundStatusEnum` 의 7종 전량. */
    private const COMMENT = '옵션 환불 상태 (RefundStatusEnum — requested: 환불 신청, approved: 환불 승인, processing: 환불 처리 중, on_hold: 환불 보류, completed: 환불 완료, rejected: 환불 반려, withdrawn: 환불 철회)';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'option_status')) {
            return;
        }

        // 주석만 바꾼다 — 타입·널 허용·기본값을 그대로 다시 적어야 MySQL 이 컬럼을 보존한다.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('option_status', 30)->comment(self::COMMENT)->change();
        });

        // 멱등: 이미 있으면 건너뛴다(부분 적용 뒤 재실행되는 경우).
        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('option_status', self::INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
