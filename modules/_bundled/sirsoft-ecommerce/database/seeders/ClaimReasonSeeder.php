<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

class ClaimReasonSeeder extends Seeder
{
    /**
     * 클레임 사유 초기 데이터를 생성합니다.
     */
    public function run(): void
    {
        $this->command->info('클레임 사유 초기 데이터 생성을 시작합니다.');

        $created = 0;

        foreach ($this->getDefaultReasons() as $reason) {
            $result = ClaimReason::firstOrCreate(
                ['type' => $reason['type'], 'code' => $reason['code']],
                $reason
            );

            if ($result->wasRecentlyCreated) {
                $created++;
                $this->command->line("  - 클레임 사유 생성: {$reason['name']['ko']} ({$reason['code']})");
            }
        }

        $total = ClaimReason::count();
        $this->command->info("클레임 사유 초기 데이터 완료: {$created}건 생성 (전체 {$total}건)");
    }

    /**
     * 기본 클레임 사유 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultReasons(): array
    {
        return [
            // 고객 귀책
            [
                'type' => 'refund',
                'code' => 'order_mistake',
                'name' => ['ko' => '주문 실수', 'en' => 'Order Mistake'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'refund',
                'code' => 'changed_mind',
                'name' => ['ko' => '단순 변심', 'en' => 'Changed Mind'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'type' => 'refund',
                'code' => 'reorder_other',
                'name' => ['ko' => '다른 상품으로 재주문', 'en' => 'Reorder with Different Product'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],

            // 판매자 귀책
            [
                'type' => 'refund',
                'code' => 'delayed_delivery',
                'name' => ['ko' => '배송 지연', 'en' => 'Delayed Delivery'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'type' => 'refund',
                'code' => 'product_info_different',
                'name' => ['ko' => '상품 정보 상이', 'en' => 'Product Info Different'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'type' => 'refund',
                'code' => 'admin_cancel',
                'name' => ['ko' => '관리자 취소', 'en' => 'Admin Cancel'],
                'fault_type' => 'seller',
                'is_user_selectable' => false,
                'is_active' => true,
                'sort_order' => 5,
            ],

            // 기타
            [
                'type' => 'refund',
                'code' => 'etc',
                'name' => ['ko' => '기타', 'en' => 'Etc'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 6,
            ],
        ];
    }
}
