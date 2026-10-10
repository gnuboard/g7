<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;

class ShippingTypeSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-ecommerce';
    }

    public function getTranslatableEntity(): string
    {
        return 'shipping_types';
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
            // 국내 배송 (기본 활성)
            ['code' => 'parcel', 'name' => ['ko' => '택배', 'en' => 'Parcel'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'direct', 'name' => ['ko' => '직접배송', 'en' => 'Direct Delivery'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'quick', 'name' => ['ko' => '퀵서비스', 'en' => 'Quick Service'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'freight', 'name' => ['ko' => '화물배송', 'en' => 'Freight'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 4],
            ['code' => 'pickup', 'name' => ['ko' => '매장수령', 'en' => 'Store Pickup'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 5],

            // 국내 배송 (기본 비활성)
            ['code' => 'express', 'name' => ['ko' => '국내특급', 'en' => 'Express'], 'category' => 'domestic', 'is_active' => false, 'sort_order' => 6],

            // 해외 배송 (기본 비활성)
            ['code' => 'international_ems', 'name' => ['ko' => '국제EMS', 'en' => 'International EMS'], 'category' => 'international', 'is_active' => false, 'sort_order' => 7],
            ['code' => 'international_standard', 'name' => ['ko' => '국제일반', 'en' => 'International Standard'], 'category' => 'international', 'is_active' => false, 'sort_order' => 8],

            // 기타 (기본 비활성)
            ['code' => 'cvs', 'name' => ['ko' => '편의점택배', 'en' => 'Convenience Store'], 'category' => 'other', 'is_active' => false, 'sort_order' => 9],
            ['code' => 'digital', 'name' => ['ko' => '디지털상품', 'en' => 'Digital'], 'category' => 'other', 'is_active' => false, 'sort_order' => 10],

            // 직접입력 (항상 마지막)
            ['code' => 'custom', 'name' => ['ko' => '직접입력', 'en' => 'Custom'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 99],
        ];
    }

    /**
     * 배송유형 초기 데이터를 동기화합니다.
     */
    public function run(): void
    {
        $this->command->info('배송유형 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $types = $this->resolveTranslatedDefaults();
        $definedCodes = [];

        foreach ($types as $type) {
            // 시더가 새로 만드는 행은 created_by 를 명시적으로 비운다(stale 정리 범위가 `created_by IS NULL` 이다).
            $existing = ShippingType::where('code', $type['code'])->exists();
            $helper->sync(
                ShippingType::class,
                ['code' => $type['code']],
                $existing ? $type : $type + ['created_by' => null],
            );
            $definedCodes[] = $type['code'];
        }

        // stale 정리는 **시드 정의에서 빠진 코드**를 걷어내는 장치다. 그런데 이 표는 운영자가
        // 설정 배송 탭에서 직접 배송유형을 추가·수정하는 표이기도 하다 — 운영자가 만든 배송유형은
        // 정의상 이 목록에 없으므로, 보호 없이 돌리면 재설치 한 번이 사용 중인 것까지 지운다.
        // 설정 화면의 삭제·코드변경 경로는 사용 중이면 400 으로 막는데(`ShippingTypeService`),
        // 이 경로만 그 판정을 거치지 않으면 같은 표에 **강도가 다른 두 삭제 경로**가 생긴다.
        // 주문 배송행은 배송유형을 `shipping_type` 코드 문자열로만 기억하므로(FK 없음), 지워지는
        // 순간 그 유형으로 접수된 전건이 카탈로그를 잃어 목록의 배송방법 라벨이 빈칸이 된다.
        //
        // 판정 근거는 `ShippingTypeService::assertSyncDeletable()` 이 쓰는 것과 **같은 조회**다.
        // 배송사와 달리 사용처 표가 `order_shippings` 하나뿐이라(마이그레이션 전수 확인) 합산할
        // 것이 없어 저장소를 직접 부른다 — 기록하는 표가 늘면 그 판정을 서비스로 올릴 것.
        $orderShippingRepository = app(OrderShippingRepositoryInterface::class);

        $inUseCodes = ShippingType::query()
            ->whereNotIn('code', $definedCodes)
            ->pluck('code')
            ->filter(fn (string $code) => $orderShippingRepository->countByShippingType($code) > 0)
            ->values()
            ->all();

        // 정리 대상은 시더가 만든 행(created_by IS NULL)뿐이다 — 운영자가 만든 미사용 배송유형은 남긴다(#727 W7).
        $deleted = $helper->cleanupStale(
            ShippingType::class,
            ['created_by' => null],
            'code',
            [...$definedCodes, ...$inUseCodes],
        );

        $preserved = count($inUseCodes);
        $count = ShippingType::count();
        $this->command->info('배송유형 동기화 완료: 정의 '.count($types)."건 / DB {$count}건 / stale 삭제 {$deleted}건 / 사용 중 {$preserved}건 보존");
    }
}
