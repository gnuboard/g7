<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 순매출 집계식 수정 이전에 쌓인 일별 판매 집계를 다시 계산합니다.
 *
 * 1.3.0 이전 집계식은 unit_price × (quantity − cancelled_quantity) 였다. 부분취소는 분할로 잔여 행의 quantity 를
 * 이미 줄이고 같은 행에 cancelled_quantity 도 더하므로 취소 수량이 두 번 빠졌다. 스케줄러는 최근 7일만 다시
 * 쓰므로 그 이전 날짜의 행은 틀린 값으로 남는다. 영향받은 날짜(매출 반영 상태 행 중 cancelled_quantity > 0 이 있는
 * 주문일)만 새 식(unit_price × quantity)으로 다시 쓴다. 상태 배지(option_status_counts)는 quantity 만 써서 정상이라
 * 건드리지 않는다. 집계 행이 없는 날짜는 만들지 않는다(대시보드가 그 날짜를 집계한 적이 없다).
 *
 * idempotent: 두 번째 실행은 같은 값을 다시 쓴다(값 변화 없음).
 * V-1 안전: Facades 와 Carbon 만 사용 — 매출 반영 상태 목록은 OrderStatusEnum::salesEligibleStatuses() 의 로컬 사본이다.
 *
 * @upgrade-path A
 */
class RecalculateEcommerceStatsNetSales implements DataMigration
{
    /** 매출 반영 상태 — OrderStatusEnum::salesEligibleStatuses() 의 로컬 사본(경로 A) */
    private const SALES_ELIGIBLE = [
        'payment_complete', 'shipping_hold', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed',
    ];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RecalculateEcommerceStatsNetSales';
    }

    /**
     * 부분취소 이력 카운터가 있는 주문일의 판매 수량·순매출을 새 식으로 다시 씁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['ecommerce_stats', 'ecommerce_order_options', 'ecommerce_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[ecommerce:1.3.0] {$table} 미존재 — 순매출 집계 재계산 스킵");

                return;
            }
        }

        // ① 영향받은 주문일 — 저장소가 CarbonImmutable::parse($date)->startOfDay() 로 묶는 것과 같은 날짜 축
        $dates = DB::table('ecommerce_order_options as oo')
            ->join('ecommerce_orders as o', 'o.id', '=', 'oo.order_id')
            ->whereNull('o.deleted_at')
            ->whereIn('oo.option_status', self::SALES_ELIGIBLE)
            ->where('oo.cancelled_quantity', '>', 0)
            ->whereNotNull('o.ordered_at')
            ->pluck('o.ordered_at')
            ->map(fn ($at) => Carbon::parse($at)->toDateString())
            ->unique()
            ->values();

        $updated = 0;
        $skippedNoRow = 0;

        foreach ($dates as $date) {
            if (! DB::table('ecommerce_stats')->whereDate('date', $date)->exists()) {
                $skippedNoRow++;

                continue;
            }

            $base = DB::table('ecommerce_order_options as oo')
                ->join('ecommerce_orders as o', 'o.id', '=', 'oo.order_id')
                ->whereNull('o.deleted_at')
                ->whereIn('oo.option_status', self::SALES_ELIGIBLE)
                ->whereBetween('o.ordered_at', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()]);

            $quantity = (int) (clone $base)->sum('oo.quantity');
            // raw 식은 연결 접두사가 별칭에도 붙어 `oo.` 로 한정할 수 없다 — unit_price·quantity 는 주문옵션에만
            // 있는 컬럼이라 비한정으로 쓴다(OrderOptionRepository::sumNetSalesOnDate 와 같은 방식).
            $amount = (float) (clone $base)->selectRaw('COALESCE(SUM(unit_price * quantity), 0) as t')->value('t');

            $updated += DB::table('ecommerce_stats')->whereDate('date', $date)->update([
                'sales_quantity' => $quantity,
                'sales_amount' => $amount,
                'updated_at' => Carbon::now(),
            ]);
        }

        $context->logger->info("[ecommerce:1.3.0] 순매출 집계 재계산 — 대상 날짜 {$dates->count()}일, 갱신 {$updated}행, 집계 행 없음 {$skippedNoRow}일");
    }
}
