<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 부분취소 주문의 헤더 적립 예정액을 남은 상품 행의 합으로 바로잡습니다.
 *
 * 1.2.1 의 부분취소 재계산은 행별 적립 예정액(`subtotal_earned_points_amount`)은 다시 썼지만 주문 헤더
 * (`total_earned_points_amount`)는 취소 전 값으로 두었다. 관리자 주문 상세 합계 줄이 취소한 상품 몫까지 적립될 것처럼
 * 보였다. 1.3.0 은 재계산 때 헤더도 맞추고, 이 스텝은 이미 틀린 채 남은 주문을 맞춘다. 적립 지급·회수 원장은 행 단위라
 * 헤더는 표시값이다 — 구매확정 주문도 보정하면 실제 지급액과 같아진다.
 *
 * 값 — 재계산 입력과 같은 회계 주체 행의 합: 취소·교환완료 원 행 제외, 원 행이 아직 교환완료가 아닌 교환 후속 행 제외,
 * 반품 행 포함. 대상 — 취소 행과 살아 있는 행이 함께 있는 주문만. 전체취소 주문은 취소 경로가 헤더를 바꾸지 않는
 * 현행 설계대로 두고, 취소 없는 주문은 대상이 아니다. 같은 상품옵션 활성 행 금액이 겹친 주문(09 와 같은 술어)은 행 값
 * 자체가 부풀어 있어 그대로 옮기면 오류가 헤더로 번지므로 바꾸지 않고 경고만 남긴다.
 *
 * DB 파사드만 쓰고 `chunkById` 로 순회한다. 이미 맞는 주문은 건드리지 않으므로 반복 실행해도 같은 결과다(멱등).
 *
 * @upgrade-path A
 */
class BackfillPartialCancelEarnedPoints implements DataMigration
{
    /** 취소 행 상태(OrderStatusEnum::CANCELLED 의 로컬 사본) */
    private const CANCELLED = 'cancelled';

    /** 교환완료로 닫힌 원 행 상태(OrderStatusEnum::EXCHANGED 의 로컬 사본) */
    private const EXCHANGED = 'exchanged';

    /** 교환 후속 행 출처(OrderOptionSourceTypeEnum::EXCHANGE 의 로컬 사본) */
    private const EXCHANGE_SOURCE = 'exchange';

    /** 한 청크의 주문 수 */
    private const CHUNK_SIZE = 500;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillPartialCancelEarnedPoints';
    }

    /**
     * 부분취소 주문의 헤더 적립 예정액을 보정하고 바꾼 주문을 남깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['ecommerce_order_options', 'ecommerce_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[ecommerce:1.3.0] {$table} 미존재 — 부분취소 적립 예정액 보정 스킵");

                return;
            }
        }

        $fixed = 0;
        $skippedOverlap = 0;

        DB::table('ecommerce_orders')
            ->select(['id', 'order_number', 'total_earned_points_amount'])
            ->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ecommerce_order_options as c')
                ->whereColumn('c.order_id', 'ecommerce_orders.id')
                ->where('c.option_status', self::CANCELLED))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ecommerce_order_options as l')
                ->whereColumn('l.order_id', 'ecommerce_orders.id')
                ->where('l.option_status', '<>', self::CANCELLED))
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use ($context, &$fixed, &$skippedOverlap) {
                foreach ($orders as $order) {
                    $rows = DB::table('ecommerce_order_options')
                        ->select(['id', 'product_option_id', 'option_status', 'source_type', 'source_option_id', 'subtotal_price', 'subtotal_paid_amount', 'subtotal_earned_points_amount'])
                        ->where('order_id', $order->id)
                        ->get();

                    if ($this->hasAmountOverlap($rows)) {
                        $skippedOverlap++;
                        $context->logger->warning('[ecommerce:1.3.0] 같은 상품옵션 행 금액이 겹친 부분취소 주문 — 헤더 적립 예정액 보정하지 않음', [
                            'order_number' => $order->order_number,
                        ]);

                        continue;
                    }

                    $after = round($this->accountableEarnedSum($rows), 2);
                    $before = round((float) $order->total_earned_points_amount, 2);

                    if (abs($after - $before) < 0.01) {
                        continue;
                    }

                    DB::table('ecommerce_orders')->where('id', $order->id)->update(['total_earned_points_amount' => $after]);
                    $fixed++;
                    $context->logger->info('[ecommerce:1.3.0] 부분취소 주문 헤더 적립 예정액 보정', [
                        'order_number' => $order->order_number,
                        'before' => $before,
                        'after' => $after,
                    ]);
                }
            });

        $context->logger->info('[ecommerce:1.3.0] 부분취소 적립 예정액 보정: '.$fixed.'건 보정, 금액 겹침으로 건너뜀 '.$skippedOverlap.'건');
    }

    /**
     * 회계 주체 행의 적립 예정액 합을 구합니다. (재계산 입력 `buildRecalcInput()` 과 같은 행 판정)
     *
     * @param  Collection<int, object>  $rows  주문의 모든 행
     * @return float 적립 예정액 합
     */
    private function accountableEarnedSum($rows): float
    {
        $exchangedSourceIds = $rows->where('option_status', self::EXCHANGED)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return (float) $rows
            ->reject(fn ($row) => in_array($row->option_status, [self::CANCELLED, self::EXCHANGED], true))
            ->reject(fn ($row) => $row->source_type === self::EXCHANGE_SOURCE
                && $row->source_option_id !== null
                && ! in_array((int) $row->source_option_id, $exchangedSourceIds, true))
            ->sum(fn ($row) => (float) $row->subtotal_earned_points_amount);
    }

    /**
     * 같은 상품옵션의 활성 행 2개 이상이 판매가 합보다 큰 결제 금액을 가졌는지 봅니다. (09 와 같은 술어)
     *
     * @param  Collection<int, object>  $rows  주문의 모든 행
     * @return bool 금액이 겹쳤으면 true
     */
    private function hasAmountOverlap($rows): bool
    {
        $groups = $rows
            ->reject(fn ($row) => in_array($row->option_status, [self::CANCELLED, self::EXCHANGED], true))
            ->reject(fn ($row) => $row->source_type === self::EXCHANGE_SOURCE)
            ->groupBy('product_option_id');

        foreach ($groups as $group) {
            if ($group->count() >= 2
                && (float) $group->sum('subtotal_paid_amount') > (float) $group->sum('subtotal_price') + 0.01) {
                return true;
            }
        }

        return false;
    }
}
