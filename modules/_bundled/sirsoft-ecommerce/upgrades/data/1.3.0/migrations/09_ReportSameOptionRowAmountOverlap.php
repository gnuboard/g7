<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 같은 상품옵션의 활성 행 금액이 겹친 주문을 찾아 알립니다 (값은 바꾸지 않음).
 *
 * 1.3.0 이전의 부분취소 재계산은 결과를 상품옵션 키로 맞춰 썼다. 한 주문에 같은 상품옵션이 상태가 다른 두 활성
 * 행으로 나뉘어 있으면(상태 분할·반품/교환 분할) 다른 상품을 부분취소할 때 두 행이 각각 두 행을 합친 금액과
 * 적립 예정액을 받았다. 그 행이 구매확정되면 적립이 많이 된다.
 *
 * 금액은 바꾸지 않는다 — 주문 금액은 거래 시점으로 동결하는 것이 1.3.0 업그레이드의 원칙이고(06), 행 금액을
 * 다시 나누려면 계산 엔진이 필요한데 경로 A 규약상 모듈 서비스를 부를 수 없다. 다음 부분취소·재활성 재계산이
 * 행 몫을 다시 쓴다. 운영자가 확정 전 적립을 확인하도록 주문번호를 남긴다.
 *
 * 탐지 술어(정상 행에서는 성립하지 않음): 같은 주문·같은 product_option_id 의 활성 행(취소·교환완료 아님,
 * 교환 후속 행 제외 — 후속 행은 원 행 금액을 의도적으로 승계한다)이 2개 이상이고, 그 행들의 결제 금액 합이
 * 판매가 합(subtotal_price — 추가옵션 포함)보다 크다.
 *
 * 읽기 전용이며 `chunkById` 로 순회한다(멱등).
 *
 * @upgrade-path A
 */
class ReportSameOptionRowAmountOverlap implements DataMigration
{
    /** 닫힌 행 상태 — 재계산 입력에서 빠지는 상태(OrderStatusEnum::CANCELLED·EXCHANGED 의 로컬 사본) */
    private const CLOSED = ['cancelled', 'exchanged'];

    /** 한 청크의 주문 수 */
    private const CHUNK_SIZE = 500;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ReportSameOptionRowAmountOverlap';
    }

    /**
     * 같은 상품옵션 행 금액이 겹친 주문을 경고하고 건수를 남깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['ecommerce_order_options', 'ecommerce_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[ecommerce:1.3.0] {$table} 미존재 — 같은 상품옵션 행 금액 점검 스킵");

                return;
            }
        }

        $found = 0;

        DB::table('ecommerce_orders')
            ->select(['id', 'order_number'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use ($context, &$found) {
                $numbers = $orders->pluck('order_number', 'id');

                $groups = DB::table('ecommerce_order_options')
                    ->select(['order_id', 'product_option_id'])
                    ->selectRaw('COUNT(*) as row_count, SUM(subtotal_paid_amount) as paid_sum, SUM(subtotal_price) as price_sum')
                    ->whereIn('order_id', $numbers->keys()->all())
                    ->whereNotIn('option_status', self::CLOSED)
                    ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '<>', 'exchange'))
                    ->groupBy('order_id', 'product_option_id')
                    ->havingRaw('COUNT(*) >= 2')
                    ->get();

                foreach ($groups as $group) {
                    if ((float) $group->paid_sum <= (float) $group->price_sum + 0.01) {
                        continue;
                    }

                    $found++;
                    $rows = DB::table('ecommerce_order_options')
                        ->where('order_id', $group->order_id)
                        ->where('product_option_id', $group->product_option_id)
                        ->whereNotIn('option_status', self::CLOSED)
                        ->pluck('id')
                        ->all();

                    $context->logger->warning('[ecommerce:1.3.0] 같은 상품옵션 행 금액이 겹친 주문 — 다음 부분취소 전 적립 확인 필요', [
                        'order_number' => $numbers[$group->order_id] ?? null,
                        'product_option_id' => (int) $group->product_option_id,
                        'rows' => $rows,
                    ]);
                }
            });

        $context->logger->info('[ecommerce:1.3.0] 같은 상품옵션 행 금액 점검: 겹친 주문·상품옵션 '.$found.'건 (금액은 바꾸지 않음)');
    }
}
