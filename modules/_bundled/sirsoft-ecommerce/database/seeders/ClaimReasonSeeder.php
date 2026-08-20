<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

/**
 * 클레임 사유 초기 데이터 시더.
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
 * 활성 언어팩의 seed/claim_reasons.json 다국어 키는 trait 가 자동 머지.
 * stale 삭제는 type(refund/exchange/return) scope 별로 수행하므로, 한 유형의 사유를
 * 추가·삭제해도 다른 유형의 운영자 등록 사유는 건드리지 않는다.
 */
class ClaimReasonSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-ecommerce';
    }

    public function getTranslatableEntity(): string
    {
        return 'claim_reasons';
    }

    public function getMatchKey(): string
    {
        return 'code';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefaults(): array
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

            // 교환 사유
            [
                'type' => 'exchange',
                'code' => 'size_mismatch',
                'name' => ['ko' => '사이즈가 맞지 않음', 'en' => 'Size Does Not Fit'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'exchange',
                'code' => 'color_mismatch',
                'name' => ['ko' => '색상/디자인이 마음에 들지 않음', 'en' => 'Color or Design Not Satisfying'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'type' => 'exchange',
                'code' => 'wrong_option_selected',
                'name' => ['ko' => '옵션을 잘못 선택함', 'en' => 'Wrong Option Selected'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'type' => 'exchange',
                'code' => 'defective_product',
                'name' => ['ko' => '상품 불량/파손', 'en' => 'Defective or Damaged Product'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'type' => 'exchange',
                'code' => 'wrong_product_delivered',
                'name' => ['ko' => '다른 상품이 배송됨', 'en' => 'Wrong Product Delivered'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'type' => 'exchange',
                'code' => 'product_info_different',
                'name' => ['ko' => '상품 정보 상이', 'en' => 'Product Info Different'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'type' => 'exchange',
                'code' => 'damaged_in_transit',
                'name' => ['ko' => '배송 중 파손', 'en' => 'Damaged in Transit'],
                'fault_type' => 'carrier',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'type' => 'exchange',
                'code' => 'etc',
                'name' => ['ko' => '기타', 'en' => 'Etc'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 7,
            ],

            // 반품 사유
            [
                'type' => 'return',
                'code' => 'changed_mind',
                'name' => ['ko' => '단순 변심', 'en' => 'Changed Mind'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'return',
                'code' => 'size_mismatch',
                'name' => ['ko' => '사이즈가 맞지 않음', 'en' => 'Size Does Not Fit'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'type' => 'return',
                'code' => 'wrong_option_selected',
                'name' => ['ko' => '옵션을 잘못 선택함', 'en' => 'Wrong Option Selected'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'type' => 'return',
                'code' => 'defective_product',
                'name' => ['ko' => '상품 불량/파손', 'en' => 'Defective or Damaged Product'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'type' => 'return',
                'code' => 'wrong_product_delivered',
                'name' => ['ko' => '다른 상품이 배송됨', 'en' => 'Wrong Product Delivered'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'type' => 'return',
                'code' => 'product_info_different',
                'name' => ['ko' => '상품 정보 상이', 'en' => 'Product Info Different'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'type' => 'return',
                'code' => 'delayed_delivery',
                'name' => ['ko' => '배송 지연', 'en' => 'Delayed Delivery'],
                'fault_type' => 'seller',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'type' => 'return',
                'code' => 'damaged_in_transit',
                'name' => ['ko' => '배송 중 파손', 'en' => 'Damaged in Transit'],
                'fault_type' => 'carrier',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'type' => 'return',
                'code' => 'etc',
                'name' => ['ko' => '기타', 'en' => 'Etc'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 8,
            ],
        ];
    }

    public function run(): void
    {
        $this->command->info('클레임 사유 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $created = 0;
        $synced = 0;
        $definedByType = [];

        foreach ($this->resolveTranslatedDefaults() as $reason) {
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
}
