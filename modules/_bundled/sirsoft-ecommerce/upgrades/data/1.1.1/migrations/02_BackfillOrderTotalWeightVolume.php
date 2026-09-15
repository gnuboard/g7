<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주문의 총 무게/부피를 주문 옵션 소계 합으로 백필합니다.
 *
 * 주문 생성 시 `total_weight`/`total_volume` 이 0 으로 고정 기록되어,
 * 배송사 연동·운임 정산이 이 값을 읽으면 무게 없는 주문으로 취급됐다.
 * 옵션에는 주문 시점 소계(subtotal_weight/subtotal_volume)가 남아 있으므로
 * 그 합으로 복원한다.
 *
 * 0 인 행만 대상으로 하며(운영자가 이후 보정한 값은 보존), 두 값이 모두 0 인
 * 주문(무게 미입력 상품만 담긴 주문)은 갱신 결과도 0 이라 재실행해도 동일하다.
 *
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Model 을 참조하지 않는다.
 */
class BackfillOrderTotalWeightVolume implements DataMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const OPTIONS_TABLE = 'ecommerce_order_options';

    public function name(): string
    {
        return 'BackfillOrderTotalWeightVolume';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::ORDERS_TABLE) || ! Schema::hasTable(self::OPTIONS_TABLE)) {
            $context->logger->warning('[ecommerce:1.1.1] 주문/주문옵션 테이블 미존재 — 스킵');

            return;
        }

        foreach (['total_weight', 'total_volume'] as $column) {
            if (! Schema::hasColumn(self::ORDERS_TABLE, $column)) {
                $context->logger->warning('[ecommerce:1.1.1] '.self::ORDERS_TABLE.".{$column} 미존재 — 스킵");

                return;
            }
        }

        $backfilled = 0;

        DB::table(self::ORDERS_TABLE)
            ->where(function ($query) {
                $query->where('total_weight', 0)->orWhereNull('total_weight');
            })
            ->where(function ($query) {
                $query->where('total_volume', 0)->orWhereNull('total_volume');
            })
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($orders) use (&$backfilled) {
                foreach ($orders as $order) {
                    $totals = DB::table(self::OPTIONS_TABLE)
                        ->where('order_id', $order->id)
                        ->selectRaw('COALESCE(SUM(subtotal_weight), 0) as weight_sum, COALESCE(SUM(subtotal_volume), 0) as volume_sum')
                        ->first();

                    $weight = (float) ($totals->weight_sum ?? 0);
                    $volume = (float) ($totals->volume_sum ?? 0);

                    if ($weight <= 0 && $volume <= 0) {
                        continue;
                    }

                    DB::table(self::ORDERS_TABLE)
                        ->where('id', $order->id)
                        ->update([
                            'total_weight' => $weight,
                            'total_volume' => $volume,
                        ]);

                    $backfilled++;
                }
            });

        $context->logger->info("[ecommerce:1.1.1] 주문 총 무게/부피 백필 완료: {$backfilled} 건");
    }
}
