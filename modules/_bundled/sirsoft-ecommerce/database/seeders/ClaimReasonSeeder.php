<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;

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
            [
                // 만료 자동취소(`CancelPendingPaymentOrdersCommand`)가 쓰는 코드.
                // 고객이 고를 사유가 아니므로 신청 화면에는 내지 않는다.
                'type' => 'refund',
                'code' => 'auto_cancel_expired',
                'name' => ['ko' => '입금 기한 만료 자동취소', 'en' => 'Auto-cancelled (payment deadline expired)'],
                'fault_type' => 'customer',
                'is_user_selectable' => false,
                'is_active' => true,
                'sort_order' => 6,
            ],

            // 기타
            [
                'type' => 'refund',
                'code' => 'etc',
                'name' => ['ko' => '기타', 'en' => 'Etc'],
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 7,
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

            // 시더가 새로 만드는 행은 created_by 를 명시적으로 비운다 — 로그인한 요청 안에서 시더가 돌아도 시드 행이
            // 운영자 행으로 보이지 않게(stale 정리 범위가 `created_by IS NULL` 이다).
            $helper->sync(
                ClaimReason::class,
                ['type' => $reason['type'], 'code' => $reason['code']],
                $existing ? $reason : $reason + ['created_by' => null],
            );
            $definedByType[$reason['type']][] = $reason['code'];

            if ($existing) {
                $synced++;
            } else {
                $created++;
                $this->command->line("  - 클레임 사유 생성: {$reason['name']['ko']} ({$reason['code']})");
            }
        }

        // stale 정리는 **시드 정의에서 빠진 코드**를 걷어내는 장치다. 그런데 이 표는 S10 이후
        // 운영자가 설정 화면에서 직접 사유를 추가·수정하는 표이기도 하다 — 운영자가 만든 사유는
        // 정의상 이 목록에 없으므로, 보호 없이 돌리면 재설치 한 번이 **사용 중인 사유까지**
        // 지운다. 지워지는 순간 접수된 신청서 전건이 사유 이름을 잃고 원시 코드로 노출된다 —
        // S10 이 400 으로 막으려던 바로 그 상태다.
        //
        // 보존 범위는 **사용 중인 것**까지다. 설정 화면의 삭제 경로도 같은 판정으로 사용 중일
        // 때만 400 으로 막고 미사용 사유는 지우게 하므로(`ClaimReasonService::deleteReason`),
        // 여기서 미사용까지 보존하면 이 경로가 오히려 화면보다 강해져 같은 표에 **강도가 다른
        // 두 삭제 경로**가 생긴다. 두 경로를 같은 강도로 맞추는 것이 이 보호의 목적이다.
        //
        // 판정은 그 서비스(단일 출처)에서 받고 삭제는 그대로 Helper 가 한다. 사용 중인 코드를
        // 보존 목록에 더하는 형태라 Helper 시그니처를 바꾸지 않는다.
        $service = app(ClaimReasonService::class);
        $totalDeleted = 0;
        $preserved = 0;

        foreach ($definedByType as $type => $codes) {
            $inUse = ClaimReason::where('type', $type)
                ->whereNotIn('code', $codes)
                ->get()
                ->filter(fn (ClaimReason $reason) => $service->countUsage($reason) > 0)
                ->pluck('code')
                ->all();

            $preserved += count($inUse);

            // 정리 대상은 시더가 만든 행(created_by IS NULL)뿐이다 — 운영자가 설정 화면에서 만든 미사용 사유까지
            // 지우면 언어팩을 켜고 끌 때마다 사라진다(#727 W7).
            $totalDeleted += $helper->cleanupStale(
                ClaimReason::class,
                ['type' => $type, 'created_by' => null],
                'code',
                [...$codes, ...$inUse],
            );
        }

        if ($preserved > 0) {
            $this->command->line("  - 사용 중이라 보존한 사유: {$preserved}건");
        }

        $total = ClaimReason::count();
        $this->command->info("클레임 사유 동기화 완료: {$created}건 생성, {$synced}건 동기화, stale {$totalDeleted}건 삭제 (전체 {$total}건)");
    }
}
