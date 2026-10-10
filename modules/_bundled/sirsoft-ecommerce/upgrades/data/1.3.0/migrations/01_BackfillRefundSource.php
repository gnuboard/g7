<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 환불 이력의 발생 출처를 polymorphic 축으로 백필합니다.
 *
 * 1.3.0 이전의 환불은 취소 파이프라인에서만 생겼고 그 연결이 order_cancel_id 하나였다.
 * 클레임 일반화 이후에는 반품·교환·직접 환불도 같은 테이블을 쓰므로 출처를
 * refund_source_type/refund_source_id 로 기록한다. order_cancel_id 는 하위호환을 위해
 * 그대로 두고 취소 발 환불에 이중 기록한다(제거하지 않는다).
 *
 * order_cancel_id 가 비어 있는 기존 행은 출처를 단정할 수 없으므로 건드리지 않는다 —
 * 'direct'(관리자 직접 환불)로 칠하면 실제로는 취소 발이었는데 연결이 유실된 행까지
 * 직접 환불로 굳어진다. 미상 상태(NULL)로 남기고 건수만 로그에 남긴다.
 *
 * 함께 기록해 두는 사실: `ecommerce_orders.total_refunded_amount` /
 * `total_refunded_points_amount` 는 1.3.0 이전에 writer 가 없어 항상 0 이었다.
 * 1.3.0 부터 환불 실행 경로가 누적하지만 과거분은 백필하지 않는다 — 1.3.0 이전 환불은 전부 취소 경로였고
 * 취소가 `total_paid_amount` 를 이미 재계산값으로 덮어써 반영돼 있어, 과거 환불액을 더하면 잔여 결제 한도
 * (`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 뺀다(근거 전문: Upgrade_1_3_0 「백필하지 않는 것」).
 * 과거 주문의 누적 표시는 0 으로 남는다.
 *
 * idempotent: 이미 백필된 행은 refund_source_type 이 채워져 대상에서 빠진다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model 을 참조하지 않는다.
 */
class BackfillRefundSource implements DataMigration
{
    private const TABLE = 'ecommerce_order_refunds';

    public function name(): string
    {
        return 'BackfillRefundSource';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->warning('[ecommerce:1.3.0] '.self::TABLE.' 테이블 미존재 — 스킵');

            return;
        }

        foreach (['refund_source_type', 'refund_source_id', 'order_cancel_id'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                $context->logger->warning('[ecommerce:1.3.0] '.self::TABLE.'.'.$column.' 컬럼 미존재 — 스킵');

                return;
            }
        }

        $updated = DB::table(self::TABLE)
            ->whereNotNull('order_cancel_id')
            ->whereNull('refund_source_type')
            ->update([
                'refund_source_type' => 'cancel',
                'refund_source_id' => DB::raw('order_cancel_id'),
            ]);

        $unresolved = DB::table(self::TABLE)
            ->whereNull('order_cancel_id')
            ->whereNull('refund_source_type')
            ->count();

        $context->logger->info(
            '[ecommerce:1.3.0] 환불 출처 백필 완료 — cancel 로 기록 '.$updated.'건, '
            .'출처 미상(NULL 유지) '.$unresolved.'건'
        );
    }
}
