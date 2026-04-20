<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

class ClaimReasonSeeder extends Seeder
{
    /**
     * 클레임 사유 초기 데이터를 동기화합니다.
     *
     * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
     * Seeder 내 모든 항목이 type='refund' scope 에 속하므로 해당 scope 로 한정 후
     * code 필드 기준 stale 삭제.
     */
    public function run(): void
    {
        $this->command->info('클레임 사유 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $created = 0;
        $synced = 0;

        // 현재 seeder 는 refund type 만 정의. scope 는 seeder 가 관리하는 type 기준으로 분리.
        $definedByType = [];

        foreach ($this->getDefaultReasons() as $reason) {
            $existing = ClaimReason::where('type', $reason['type'])->where('code', $reason['code'])->exists();

            $helper->sync(
                ClaimReason::class,
                ['type' => $reason['type'], 'code' => $reason['code']],
                $reason,
            );
            $definedByType[$reason['type']][] = $reason['code'];

            if ($existing) {
                $synced++;
            } else {
                $created++;
                $this->command->line("  - 클레임 사유 생성: {$reason['name']['ko']} ({$reason['code']})");
            }
        }

        // 완전 동기화: type 별로 seeder 에서 제거된 code 삭제 (user_overrides 무관)
        $totalDeleted = 0;
        foreach ($definedByType as $type => $codes) {
            $totalDeleted += $helper->cleanupStale(
                ClaimReason::class,
                ['type' => $type],
                'code',
                $codes,
            );
        }

        $total = ClaimReason::count();
        $this->command->info("클레임 사유 동기화 완료: {$created}건 생성, {$synced}건 동기화, stale {$totalDeleted}건 삭제 (전체 {$total}건)");
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
