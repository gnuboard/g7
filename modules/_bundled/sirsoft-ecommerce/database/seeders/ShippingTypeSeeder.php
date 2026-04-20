<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

class ShippingTypeSeeder extends Seeder
{
    /**
     * 배송유형 초기 데이터를 동기화합니다.
     *
     * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
     * 사용자 수정(user_overrides) 은 helper 내부 HasUserOverrides trait 를 통해 보존됩니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('배송유형 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $types = $this->getDefaultTypes();
        $definedCodes = [];

        foreach ($types as $type) {
            $helper->sync(
                ShippingType::class,
                ['code' => $type['code']],
                $type,
            );
            $definedCodes[] = $type['code'];
        }

        // 완전 동기화: seeder 에서 제거된 배송유형 삭제 (user_overrides 무관)
        $deleted = $helper->cleanupStale(
            ShippingType::class,
            [],
            'code',
            $definedCodes,
        );

        $count = ShippingType::count();
        $this->command->info("배송유형 동기화 완료: 정의 ".count($types)."건 / DB {$count}건 / stale 삭제 {$deleted}건");
    }

    /**
     * 배송유형 기본 데이터 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultTypes(): array
    {
        return [
            // 국내 배송 (기본 활성)
            [
                'code' => 'parcel',
                'name' => ['ko' => '택배', 'en' => 'Parcel'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'direct',
                'name' => ['ko' => '직접배송', 'en' => 'Direct Delivery'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'quick',
                'name' => ['ko' => '퀵서비스', 'en' => 'Quick Service'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'freight',
                'name' => ['ko' => '화물배송', 'en' => 'Freight'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'code' => 'pickup',
                'name' => ['ko' => '매장수령', 'en' => 'Store Pickup'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 5,
            ],

            // 국내 배송 (기본 비활성)
            [
                'code' => 'express',
                'name' => ['ko' => '국내특급', 'en' => 'Express'],
                'category' => 'domestic',
                'is_active' => false,
                'sort_order' => 6,
            ],

            // 해외 배송 (기본 비활성)
            [
                'code' => 'international_ems',
                'name' => ['ko' => '국제EMS', 'en' => 'International EMS'],
                'category' => 'international',
                'is_active' => false,
                'sort_order' => 7,
            ],
            [
                'code' => 'international_standard',
                'name' => ['ko' => '국제일반', 'en' => 'International Standard'],
                'category' => 'international',
                'is_active' => false,
                'sort_order' => 8,
            ],

            // 기타 (기본 비활성)
            [
                'code' => 'cvs',
                'name' => ['ko' => '편의점택배', 'en' => 'Convenience Store'],
                'category' => 'other',
                'is_active' => false,
                'sort_order' => 9,
            ],
            [
                'code' => 'digital',
                'name' => ['ko' => '디지털상품', 'en' => 'Digital'],
                'category' => 'other',
                'is_active' => false,
                'sort_order' => 10,
            ],

            // 직접입력 (항상 마지막)
            [
                'code' => 'custom',
                'name' => ['ko' => '직접입력', 'en' => 'Custom'],
                'category' => 'domestic',
                'is_active' => true,
                'sort_order' => 99,
            ],
        ];
    }
}
