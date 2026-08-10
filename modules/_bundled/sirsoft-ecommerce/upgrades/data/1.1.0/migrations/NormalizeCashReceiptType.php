<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 현금영수증 발급 용도(cash_receipt_type)의 레거시 값을 정규화합니다.
 *
 * `ecommerce_order_payments` 마이그레이션의 comment 는 income/expense 였으나,
 * KG이니시스 플러그인의 AdminCashReceiptController 는 income_deduction/expenditure_proof 를
 * 기록해 왔다. CashReceiptType Enum 을 SSoT 로 삼아 두 값을 정규화한다.
 *
 * idempotent: 이미 정규화된 값은 매칭되지 않아 no-op.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum 클래스를 참조하지 않는다
 * (그 시점의 Enum 정의가 바뀌면 이 스텝의 동작이 달라지므로).
 */
class NormalizeCashReceiptType implements DataMigration
{
    private const TABLE = 'ecommerce_order_payments';

    private const COLUMN = 'cash_receipt_type';

    /**
     * 레거시 값 → 정규화 값 매핑
     *
     * @var array<string, string>
     */
    private const LEGACY_MAP = [
        'income_deduction' => 'income',
        'expenditure_proof' => 'expense',
    ];

    public function name(): string
    {
        return 'NormalizeCashReceiptType';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            $context->logger->warning('[ecommerce:1.1.0] '.self::TABLE.'.'.self::COLUMN.' 미존재 — 스킵');

            return;
        }

        foreach (self::LEGACY_MAP as $legacy => $normalized) {
            $updated = DB::table(self::TABLE)
                ->where(self::COLUMN, $legacy)
                ->update([self::COLUMN => $normalized]);

            if ($updated > 0) {
                $context->logger->info("[ecommerce:1.1.0] {$legacy} → {$normalized}: {$updated} 건 정규화");
            }
        }

        $context->logger->info('[ecommerce:1.1.0] cash_receipt_type 정규화 완료');
    }
}
