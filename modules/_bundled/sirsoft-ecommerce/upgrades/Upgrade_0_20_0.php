<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

/**
 * v0.20.0 업그레이드 스텝
 *
 * 기본 클레임 사유(환불/취소) 초기 데이터 삽입.
 * 기존 설치 환경에서 클레임 사유가 비어있어 주문 취소가 불가능한 문제를 해결합니다.
 */
class Upgrade_0_20_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_claim_reasons')) {
            $context->logger->warning('[v0.20.0] ecommerce_claim_reasons 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        $created = 0;

        foreach ($this->getDefaultReasons() as $reason) {
            $result = ClaimReason::firstOrCreate(
                ['type' => $reason['type'], 'code' => $reason['code']],
                $reason
            );

            if ($result->wasRecentlyCreated) {
                $created++;
            }
        }

        $total = ClaimReason::count();
        $context->logger->info("[v0.20.0] 클레임 사유 초기 데이터: {$created}건 생성 (전체 {$total}건)");
    }

    /**
     * 기본 클레임 사유 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultReasons(): array
    {
        return [
            ['type' => 'refund', 'code' => 'order_mistake', 'name' => ['ko' => '주문 실수', 'en' => 'Order Mistake'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 0],
            ['type' => 'refund', 'code' => 'changed_mind', 'name' => ['ko' => '단순 변심', 'en' => 'Changed Mind'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 1],
            ['type' => 'refund', 'code' => 'reorder_other', 'name' => ['ko' => '다른 상품으로 재주문', 'en' => 'Reorder with Different Product'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 2],
            ['type' => 'refund', 'code' => 'delayed_delivery', 'name' => ['ko' => '배송 지연', 'en' => 'Delayed Delivery'], 'fault_type' => 'seller', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 3],
            ['type' => 'refund', 'code' => 'product_info_different', 'name' => ['ko' => '상품 정보 상이', 'en' => 'Product Info Different'], 'fault_type' => 'seller', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 4],
            ['type' => 'refund', 'code' => 'admin_cancel', 'name' => ['ko' => '관리자 취소', 'en' => 'Admin Cancel'], 'fault_type' => 'seller', 'is_user_selectable' => false, 'is_active' => true, 'sort_order' => 5],
            ['type' => 'refund', 'code' => 'etc', 'name' => ['ko' => '기타', 'en' => 'Etc'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 6],
        ];
    }
}
