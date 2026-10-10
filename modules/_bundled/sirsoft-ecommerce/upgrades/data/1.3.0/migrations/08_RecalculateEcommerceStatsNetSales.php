<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 일별 판매 집계(ecommerce_stats) 기존 행 전부를 사이트 하루·새 순매출 식으로 다시 씁니다.
 *
 * 1.3.0 이전 집계는 ① 순매출을 unit_price × (quantity − cancelled_quantity) 로 계산해 부분취소 수량을 두 번 뺐고
 * (부분취소는 분할로 잔여 행의 quantity 를 이미 줄이고 같은 행에 cancelled_quantity 도 더한다) ② 날짜를 UTC 하루로
 * 묶었다. 1.3.0 런타임은 순매출을 unit_price × quantity 로, 하루를 사이트 표준시(`app.default_user_timezone`)로 센다
 * (OrderOptionRepository::orderedOnDateQuery, EcommerceDashboardService::siteToday). 스케줄러는 최근 7일만 다시 쓰므로
 * 그 이전 행은 이 스텝이 고친다 — 기존 행 전부(결정 2026-10-09 Q-4)의 판매 수량·순매출·상태 배지
 * (option_status_counts)를 그 행 날짜의 사이트 하루로 다시 센다. 그 하루에 주문이 없으면 0 이 된다.
 * 집계 행이 없는 날짜는 만들지 않는다(대시보드가 그 날짜를 집계한 적이 없다).
 *
 * idempotent: 런타임과 같은 축·같은 식이라 다시 돌려도(`module:update --force` 포함) 같은 값을 쓴다.
 * 순회: ecommerce_stats id 커서로 500행씩 끊어 읽는다.
 * V-1 안전: Facades 와 Carbon 만 사용 — 매출 반영 상태 목록·배지 버킷은 런타임의 로컬 사본이다.
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
     * 상태 배지 7버킷 — EcommerceDashboardService::buildBadgeBuckets() 의 로컬 사본(배지 키 => option_status 값)
     */
    private const BADGE_BUCKETS = [
        'pending_payment' => 'pending_payment',
        'payment_complete' => 'payment_complete',
        'preparing' => 'preparing',
        'shipping_ready' => 'shipping_ready',
        'shipping' => 'shipping',
        'cancellations' => 'cancelled',
        'returns' => 'returned',
    ];

    /** 한 번에 읽는 집계 행 수 */
    private const CHUNK = 500;

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
     * 기존 집계 행 전부를 그 날짜의 사이트 하루 기준으로 다시 셉니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['ecommerce_stats', 'ecommerce_order_options', 'ecommerce_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[ecommerce:1.3.0] {$table} 미존재 — 일별 판매 집계 재집계 스킵");

                return;
            }
        }

        $timezone = config('app.default_user_timezone') ?: 'UTC';
        $rows = 0;
        $changed = 0;
        $lastId = 0;

        while (true) {
            $stats = DB::table('ecommerce_stats')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'date', 'sales_quantity', 'sales_amount']);

            if ($stats->isEmpty()) {
                break;
            }

            foreach ($stats as $stat) {
                $lastId = (int) $stat->id;
                $rows++;

                [$quantity, $amount, $badges] = $this->aggregateSiteDay(substr((string) $stat->date, 0, 10), $timezone);

                if ((int) $stat->sales_quantity !== $quantity || round((float) $stat->sales_amount, 2) !== round($amount, 2)) {
                    $changed++;
                }

                DB::table('ecommerce_stats')->where('id', $stat->id)->update([
                    'sales_quantity' => $quantity,
                    'sales_amount' => $amount,
                    'option_status_counts' => json_encode($badges),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }

        $context->logger->info("[ecommerce:1.3.0] 일별 판매 집계 재집계(사이트 하루 {$timezone}) — 집계 행 {$rows}행, 판매 수량·순매출이 바뀐 행 {$changed}행");
    }

    /**
     * 사이트 하루 하나의 판매 수량·순매출·상태 배지를 셉니다.
     *
     * 하루 경계는 런타임 OrderOptionRepository::orderedOnDateQuery() 와 같다 — 사이트 시간대의 그 날 0시부터
     * 하루를 더한 직전까지를 UTC 범위로 푼다(일광절약시간 지역 대비).
     *
     * @param  string  $date  집계 날짜 (Y-m-d, 사이트 표준시)
     * @param  string  $timezone  사이트 시간대
     * @return array{0: int, 1: float, 2: array<string, int>} [판매 수량, 순매출, 상태 배지 7버킷]
     */
    private function aggregateSiteDay(string $date, string $timezone): array
    {
        $start = Carbon::parse($date, $timezone)->startOfDay();
        $from = $start->copy()->utc()->format('Y-m-d H:i:s');
        $to = $start->copy()->addDay()->utc()->format('Y-m-d H:i:s');

        $base = DB::table('ecommerce_order_options as oo')
            ->join('ecommerce_orders as o', 'o.id', '=', 'oo.order_id')
            ->whereNull('o.deleted_at')
            ->where('o.ordered_at', '>=', $from)
            ->where('o.ordered_at', '<', $to);

        // raw 식은 연결 접두사가 별칭에도 붙어 `oo.` 로 한정할 수 없다 — unit_price·quantity 는 주문옵션에만
        // 있는 컬럼이라 비한정으로 쓴다(OrderOptionRepository::sumNetSalesOnDate 와 같은 방식).
        $sales = (clone $base)
            ->whereIn('oo.option_status', self::SALES_ELIGIBLE)
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(unit_price * quantity), 0) as amount')
            ->first();

        $byStatus = (clone $base)
            ->selectRaw('option_status as status, COALESCE(SUM(quantity), 0) as qty')
            ->groupBy('option_status')
            ->pluck('qty', 'status');

        $badges = [];
        foreach (self::BADGE_BUCKETS as $key => $status) {
            $badges[$key] = (int) ($byStatus[$status] ?? 0);
        }

        return [(int) ($sales->qty ?? 0), (float) ($sales->amount ?? 0), $badges];
    }
}
