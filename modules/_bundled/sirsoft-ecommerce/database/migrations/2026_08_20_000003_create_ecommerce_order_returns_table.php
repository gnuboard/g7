<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 반품 신청서 테이블을 생성합니다.
 *
 * 교환 신청서에서 재발송·신규옵션·차액 컬럼을 제거하고, 반품완료 후 생성되는
 * 환불신청서를 역참조하는 refund_id 를 갖는다. 배송비는 편도 기준이다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 부분 실패 뒤 재실행이나 이미 만들어진 설치본에서 "Base table already exists" 로 멈추지 않게 한다.
        if (Schema::hasTable('ecommerce_order_returns')) {
            return;
        }

        Schema::create('ecommerce_order_returns', function (Blueprint $table) {
            $table->id()->comment('반품 ID');
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->string('return_number', 50)->unique()->comment('고유 반품번호 (SequenceType::ORDER_RETURN, 접두사 RT)');
            $table->string('return_status', 30)->comment('반품 상태 (ReturnStatusEnum — requested: 반품신청, payment_pending: 반품결제대기, paid: 반품결제완료, approved: 반품승인, on_hold: 반품보류, rejected: 반품반려, collection_ready: 반품회수준비중, collecting: 반품회수중, collected: 반품회수도착, completed: 반품완료, withdrawn: 반품철회)');

            $table->string('reason_code', 50)->comment('반품 사유 코드 (ecommerce_claim_reasons.code, type=return 참조 — 폭은 카탈로그 code 와 같아야 한다)');
            $table->text('reason_detail')->nullable()->comment('상세 사유 (기타 선택 시)');
            $table->string('fault_type', 20)->comment('귀책 구분 스냅샷 (ClaimReasonFaultTypeEnum — customer: 고객 귀책, seller: 판매자 귀책, carrier: 배송사 귀책)');

            $table->string('charge_method', 30)->nullable()->comment('청구 방식 (ClaimChargeMethodEnum — pay_on_request: 신청 시점 결제, pay_on_approval: 승인 시점 결제, deduct_from_refund: 환불액에서 차감 / 청구액 0원이면 NULL)');
            $table->string('shipping_fee_mode', 30)->comment('배송비 산정 모드 스냅샷 (ClaimShippingFeeModeEnum — fixed: 고정액, original_policy: 원 배송정책 재계산)');
            $table->decimal('claim_shipping_fee', 12, 2)->default(0)->comment('클레임 배송비 (반품은 편도, 판매자/배송사 귀책이면 0)');
            $table->text('mc_claim_shipping_fee')->nullable()->comment('다통화 클레임 배송비 (통화코드 → 금액)');
            $table->mediumText('shipping_fee_snapshot')->nullable()->comment('배송비 산정 근거 스냅샷 JSON (정책/구간/도서산간/안분)');
            $table->decimal('total_charge_amount', 12, 2)->default(0)->comment('총 청구 금액 (배송비)');
            $table->text('mc_total_charge_amount')->nullable()->comment('다통화 총 청구 금액 (통화코드 → 금액)');
            $table->string('currency_code', 10)->default('KRW')->comment('신청 시점 기준통화 코드');

            $table->mediumText('items_snapshot')->comment('신청 시점 대상 옵션/수량 스냅샷');
            $table->mediumText('return_address_snapshot')->nullable()->comment('회수지 주소 스냅샷 (배송정책 클레임 회수지 복사)');

            $table->unsignedBigInteger('collection_carrier_id')->nullable()->comment('회수 택배사 ID');
            $table->string('collection_tracking_number', 50)->nullable()->comment('회수 운송장 번호');
            $table->timestamp('collection_completed_at')->nullable()->comment('회수 도착 시각');

            $table->unsignedBigInteger('refund_id')->nullable()->comment('반품완료 후 생성된 환불신청서 ID');

            $table->boolean('is_stock_restored')->default(false)->comment('재고 복구 완료 여부 (1: 복구함, 0: 복구 안 함 — 회수 도착 시 관리자 선택)');
            $table->foreignId('requested_by')->nullable()->comment('신청자 (비회원이면 NULL)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->comment('승인 시각');
            $table->timestamp('on_hold_at')->nullable()->comment('보류 시각');
            $table->timestamp('rejected_at')->nullable()->comment('반려 시각');
            $table->timestamp('withdrawn_at')->nullable()->comment('철회 시각');
            $table->timestamp('completed_at')->nullable()->comment('반품 완료 시각');
            $table->text('reject_reason')->nullable()->comment('반려 사유');
            $table->text('admin_memo')->nullable()->comment('관리자 메모');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_returns_order_id_index');
            $table->index('return_status', 'ecommerce_order_returns_status_index');
            $table->index(['order_id', 'return_status'], 'ecommerce_order_returns_order_status_index');
            $table->index('requested_by', 'ecommerce_order_returns_requested_by_index');
            $table->index('created_at', 'ecommerce_order_returns_created_at_index');
            $table->index('refund_id', 'ecommerce_order_returns_refund_id_index');

            $table->foreign('collection_carrier_id', 'fk_rt_collection_carrier_id')
                ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            $table->foreign('refund_id', 'fk_rt_refund_id')
                ->references('id')->on('ecommerce_order_refunds')->nullOnDelete();

            $table->comment('반품 신청서');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_returns')) {
            Schema::dropIfExists('ecommerce_order_returns');
        }
    }
};
