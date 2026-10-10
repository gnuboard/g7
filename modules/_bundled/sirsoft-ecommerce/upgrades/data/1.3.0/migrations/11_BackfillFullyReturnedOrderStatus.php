<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 상품이 전부 반품완료(일부는 취소)인 주문의 주문 상태를 「반품완료」로 바로잡습니다.
 *
 * 출시본 1.2.1 에는 반품 기능이 없어 이 스텝의 대상이 없다. 1.3.0 개발 중 빌드로 먼저 설치한 사이트에서는 반품 완료가
 * 상품 행 상태만 반품완료로 바꾸고 주문 상태는 그대로 두어, 전량 반품을 마친 주문이 「배송완료」 등으로 남았다. 목록에서
 * 끝난 주문으로 보이지 않고, 개인정보 보존기간 파기도 구매확정·취소만 종결로 봐서 영영 대상이 되지 못했다. 1.3.0 은 반품
 * 완료 때 살아 있는 상품이 없으면 주문을 반품완료로 바꾸고, 이 스텝은 그렇게 남은 주문을 맞춘다.
 *
 * 대상 — 모든 행이 반품완료 또는 취소이고(교환완료로 닫힌 원 행은 세지 않는다) 반품완료 행이 하나 이상인 주문(이미
 * 반품완료·취소인 주문 제외). 진행 중 클레임 행이 하나라도 있으면 끝난 주문이 아니므로 대상이 아니다. 알림·로그 훅은 내지 않는다(경로 A — 과거 사건이다).
 *
 * 개발 빌드 사이트는 모듈 버전이 이미 1.3.0 이라 업그레이드가 1.3.0 스텝을 고르지 않는다(`> fromVersion` 만 실행). 그런
 * 사이트는 `php artisan module:update sirsoft-ecommerce --force` 로 1.3.0 스텝을 다시 돌려야 이 보정이 적용된다(7.1.0 출시
 * 점검 EU-6). 같은 묶음의 다른 스텝도 모두 멱등이고, 일별 판매 재집계도 런타임과 같은 축이라 재실행이 값을 틀리게 만들지 않는다.
 *
 * DB 파사드만 쓰고 `chunkById` 로 순회한다. 바뀐 주문은 다시 대상이 되지 않으므로 반복 실행해도 같은 결과다(멱등).
 *
 * @upgrade-path A
 */
class BackfillFullyReturnedOrderStatus implements DataMigration
{
    /** 반품완료 상태(OrderStatusEnum::RETURNED 의 로컬 사본) */
    private const RETURNED = 'returned';

    /** 취소 상태(OrderStatusEnum::CANCELLED 의 로컬 사본) */
    private const CANCELLED = 'cancelled';

    /** 교환완료 상태(OrderStatusEnum::EXCHANGED 의 로컬 사본) */
    private const EXCHANGED = 'exchanged';

    /** 한 청크의 주문 수 */
    private const CHUNK_SIZE = 500;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillFullyReturnedOrderStatus';
    }

    /**
     * 전량 반품 주문의 상태를 반품완료로 바꾸고 바꾼 주문을 남깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['ecommerce_order_options', 'ecommerce_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[ecommerce:1.3.0] {$table} 미존재 — 전량 반품 주문 상태 보정 스킵");

                return;
            }
        }

        $fixed = 0;

        DB::table('ecommerce_orders')
            ->select(['id', 'order_number', 'order_status'])
            ->whereNull('deleted_at')
            ->whereNotIn('order_status', [self::RETURNED, self::CANCELLED])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ecommerce_order_options as r')
                ->whereColumn('r.order_id', 'ecommerce_orders.id')
                ->where('r.option_status', self::RETURNED))
            // 교환완료 원 행은 받은 상품이 승계해 닫힌 행이라 살아 있는 상품으로 세지 않는다(서비스 판정과 같다).
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ecommerce_order_options as o')
                ->whereColumn('o.order_id', 'ecommerce_orders.id')
                ->whereNotIn('o.option_status', [self::RETURNED, self::CANCELLED, self::EXCHANGED]))
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use ($context, &$fixed) {
                foreach ($orders as $order) {
                    DB::table('ecommerce_orders')->where('id', $order->id)->update(['order_status' => self::RETURNED]);
                    $fixed++;
                    $context->logger->info('[ecommerce:1.3.0] 전량 반품 주문 상태 보정', [
                        'order_number' => $order->order_number,
                        'before' => $order->order_status,
                        'after' => self::RETURNED,
                    ]);
                }
            });

        $context->logger->info('[ecommerce:1.3.0] 전량 반품 주문 상태 보정: '.$fixed.'건');
    }
}
