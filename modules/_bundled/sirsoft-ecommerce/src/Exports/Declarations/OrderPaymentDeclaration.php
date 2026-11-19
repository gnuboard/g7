<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 주문 결제 (OrderPayment) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class OrderPaymentDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return OrderPayment::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'payment_status' => ['type' => 'enum', 'personal' => false, 'enum' => PaymentStatusEnum::class],
            'pg_provider' => ['type' => 'string', 'personal' => false],
            'transaction_id' => ['type' => 'string', 'personal' => false],
            'merchant_order_id' => ['type' => 'string', 'personal' => false],
            'payment_method' => ['type' => 'string', 'personal' => false],
            'payment_device' => ['type' => 'string', 'personal' => false],
            'paid_amount_local' => ['type' => 'money', 'personal' => false],
            'paid_amount_base' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'vat_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'currency' => ['type' => 'string', 'personal' => false],
            'card_name' => ['type' => 'string', 'personal' => false],
            'card_number_masked' => ['type' => 'string', 'personal' => true],
            'card_approval_number' => ['type' => 'string', 'personal' => false],
            'card_installment_months' => ['type' => 'integer', 'personal' => false],
            'is_interest_free' => ['type' => 'boolean', 'personal' => false],
            'vbank_name' => ['type' => 'string', 'personal' => false],
            'vbank_number' => ['type' => 'string', 'personal' => true],
            'vbank_holder' => ['type' => 'string', 'personal' => true],
            'vbank_due_at' => ['type' => 'datetime', 'personal' => false],
            'vbank_issued_at' => ['type' => 'datetime', 'personal' => false],
            'dbank_name' => ['type' => 'string', 'personal' => false],
            'dbank_account' => ['type' => 'string', 'personal' => false],
            'dbank_holder' => ['type' => 'string', 'personal' => false],
            'depositor_name' => ['type' => 'string', 'personal' => true],
            'deposit_due_at' => ['type' => 'datetime', 'personal' => false],
            'is_escrow' => ['type' => 'boolean', 'personal' => false],
            'buyer_name' => ['type' => 'string', 'personal' => true],
            'buyer_email' => ['type' => 'string', 'personal' => true],
            'buyer_phone' => ['type' => 'string', 'personal' => true],
            'is_cash_receipt_requested' => ['type' => 'boolean', 'personal' => false],
            'is_cash_receipt_issued' => ['type' => 'boolean', 'personal' => false],
            'cash_receipt_type' => ['type' => 'string', 'personal' => false],
            'cash_receipt_issued_at' => ['type' => 'datetime', 'personal' => false],
            'cancelled_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'cancelled_vat_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'cancel_reason' => ['type' => 'string', 'personal' => false],
            'refund_bank_name' => ['type' => 'string', 'personal' => false],
            'refund_bank_account' => ['type' => 'string', 'personal' => true],
            'refund_bank_holder' => ['type' => 'string', 'personal' => true],
            'payment_name' => ['type' => 'string', 'personal' => false],
            'payment_started_at' => ['type' => 'datetime', 'personal' => false],
            'paid_at' => ['type' => 'datetime', 'personal' => false],
            'cancelled_at' => ['type' => 'datetime', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'id' => ExportExclusion::PRIMARY_KEY,
            'order_id' => ExportExclusion::FOREIGN_KEY,
            'embedded_pg_provider' => ExportExclusion::INTERNAL,
            'currency_snapshot' => ExportExclusion::SNAPSHOT,
            'mc_paid_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_cancelled_amount' => ExportExclusion::MULTI_CURRENCY,
            'vbank_code' => ExportExclusion::CODE_WITH_NAME,
            'dbank_code' => ExportExclusion::CODE_WITH_NAME,
            'refund_bank_code' => ExportExclusion::CODE_WITH_NAME,
            'cash_receipt_identifier_type' => ExportExclusion::SECRET,
            'cash_receipt_identifier' => ExportExclusion::SECRET,
            'cash_receipt_identifier_encrypted' => ExportExclusion::SECRET,
            'cancel_history' => ExportExclusion::SNAPSHOT,
            'receipt_url' => ExportExclusion::INTERNAL,
            'user_agent' => ExportExclusion::INTERNAL,
            'payment_meta' => ExportExclusion::INTERNAL,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
        ];
    }
}
