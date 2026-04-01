<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.14.0 업그레이드 스텝
 *
 * - 클래임 사유 테이블(ecommerce_claim_reasons) 존재 확인
 * - 기존 CancelReasonTypeEnum 7개 값을 DB 시드 데이터로 삽입
 */
class Upgrade_0_14_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->seedDefaultRefundReasons($context);
    }

    /**
     * 기존 CancelReasonTypeEnum 값을 ecommerce_claim_reasons에 시드합니다.
     *
     * 이미 존재하는 code는 스킵합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function seedDefaultRefundReasons(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_claim_reasons')) {
            $context->logger->warning('[v0.14.0] ecommerce_claim_reasons 테이블이 없습니다. 마이그레이션이 정상 실행되었는지 확인해주세요.');

            return;
        }

        $defaults = [
            ['type' => 'refund', 'code' => 'order_mistake', 'name' => '{"ko":"주문 실수","en":"Order Mistake"}', 'fault_type' => 'customer', 'is_user_selectable' => true, 'sort_order' => 0],
            ['type' => 'refund', 'code' => 'changed_mind', 'name' => '{"ko":"단순 변심","en":"Changed Mind"}', 'fault_type' => 'customer', 'is_user_selectable' => true, 'sort_order' => 1],
            ['type' => 'refund', 'code' => 'reorder_other', 'name' => '{"ko":"다른 상품으로 재주문","en":"Reorder with Different Product"}', 'fault_type' => 'customer', 'is_user_selectable' => true, 'sort_order' => 2],
            ['type' => 'refund', 'code' => 'delayed_delivery', 'name' => '{"ko":"배송 지연","en":"Delayed Delivery"}', 'fault_type' => 'seller', 'is_user_selectable' => true, 'sort_order' => 3],
            ['type' => 'refund', 'code' => 'product_info_different', 'name' => '{"ko":"상품 정보 상이","en":"Product Info Different"}', 'fault_type' => 'seller', 'is_user_selectable' => true, 'sort_order' => 4],
            ['type' => 'refund', 'code' => 'admin_cancel', 'name' => '{"ko":"관리자 취소","en":"Admin Cancel"}', 'fault_type' => 'seller', 'is_user_selectable' => false, 'sort_order' => 5],
            ['type' => 'refund', 'code' => 'etc', 'name' => '{"ko":"기타","en":"Etc"}', 'fault_type' => 'customer', 'is_user_selectable' => true, 'sort_order' => 6],
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($defaults as $reason) {
            $exists = DB::table('ecommerce_claim_reasons')
                ->where('type', $reason['type'])
                ->where('code', $reason['code'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DB::table('ecommerce_claim_reasons')->insert(array_merge($reason, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $inserted++;
        }

        $context->logger->info("[v0.14.0] 환불 사유 시드 완료 (신규: {$inserted}건, 스킵: {$skipped}건)");
    }
}
