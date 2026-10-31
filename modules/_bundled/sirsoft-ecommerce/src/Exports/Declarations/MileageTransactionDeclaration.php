<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;

/**
 * 마일리지 거래 (MileageTransaction) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class MileageTransactionDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return MileageTransaction::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'personal' => false],
            'currency' => ['type' => 'string', 'personal' => false],
            'type' => ['type' => 'enum', 'personal' => false],
            'amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'remaining_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'balance_after' => ['type' => 'money', 'personal' => false],
            'description' => ['type' => 'string', 'personal' => false],
            'memo' => ['type' => 'string', 'personal' => true],
            'expires_at' => ['type' => 'datetime', 'personal' => false],
            'expired_at' => ['type' => 'datetime', 'personal' => false],
            'created_at' => ['type' => 'datetime', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'user_id' => ExportExclusion::FOREIGN_KEY,
            'order_id' => ExportExclusion::FOREIGN_KEY,
            'order_option_id' => ExportExclusion::FOREIGN_KEY,
            'order_cancel_id' => ExportExclusion::FOREIGN_KEY,
            'claim_payment_id' => ExportExclusion::FOREIGN_KEY,
            'order_refund_id' => ExportExclusion::FOREIGN_KEY,
            'source_transaction_id' => ExportExclusion::FOREIGN_KEY,
            'granted_by' => ExportExclusion::FOREIGN_KEY,
            'metadata' => ExportExclusion::INTERNAL,
            'updated_at' => ExportExclusion::RECORD_META,
            'purchase_earn_option_key' => ExportExclusion::INTERNAL,
        ];
    }
}
