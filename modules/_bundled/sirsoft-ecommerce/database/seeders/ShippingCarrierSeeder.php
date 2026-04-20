<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 배송사 초기 데이터 시더.
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
 * 사용자가 관리자 UI 에서 수정한 필드(user_overrides)는 보존하고,
 * 시더 정의에서 제거된 배송사만 정리한다.
 */
class ShippingCarrierSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('배송사 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $created = 0;
        $synced = 0;
        $codes = [];

        foreach ($this->getDefaultCarriers() as $carrier) {
            $existing = ShippingCarrier::where('code', $carrier['code'])->exists();

            $helper->sync(
                ShippingCarrier::class,
                ['code' => $carrier['code']],
                $carrier,
            );
            $codes[] = $carrier['code'];

            if ($existing) {
                $synced++;
            } else {
                $created++;
                $this->command->line("  - 배송사 생성: {$carrier['name']['ko']} ({$carrier['code']})");
            }
        }

        // 완전 동기화: 시더 정의에서 제거된 배송사 정리 (user_overrides 무관)
        $deleted = $helper->cleanupStale(
            ShippingCarrier::class,
            [],
            'code',
            $codes,
        );

        $total = ShippingCarrier::count();
        $this->command->info("배송사 동기화 완료: {$created}건 생성, {$synced}건 동기화, stale {$deleted}건 삭제 (전체 {$total}건)");
    }

    /**
     * 기본 배송사 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultCarriers(): array
    {
        return [
            // 국내 배송사
            [
                'code' => 'cj',
                'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'hanjin',
                'name' => ['ko' => '한진택배', 'en' => 'Hanjin Express'],
                'type' => 'domestic',
                'tracking_url' => 'https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'lotte',
                'name' => ['ko' => '롯데택배', 'en' => 'Lotte Global Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'logen',
                'name' => ['ko' => '로젠택배', 'en' => 'Logen Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://www.ilogen.com/web/personal/trace/{tracking_number}',
                'is_active' => true,
                'sort_order' => 4,
            ],

            // 국제 배송사
            [
                'code' => 'ups',
                'name' => ['ko' => 'UPS', 'en' => 'UPS'],
                'type' => 'international',
                'tracking_url' => 'https://www.ups.com/track?tracknum={tracking_number}',
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'code' => 'ems',
                'name' => ['ko' => 'EMS', 'en' => 'EMS'],
                'type' => 'international',
                'tracking_url' => 'https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}',
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'code' => 'dhl',
                'name' => ['ko' => 'DHL', 'en' => 'DHL'],
                'type' => 'international',
                'tracking_url' => 'https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}',
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'code' => 'fedex',
                'name' => ['ko' => 'FedEx', 'en' => 'FedEx'],
                'type' => 'international',
                'tracking_url' => 'https://www.fedex.com/fedextrack/?tracknumbers={tracking_number}',
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'code' => 'sf',
                'name' => ['ko' => 'SF Express', 'en' => 'SF Express'],
                'type' => 'international',
                'tracking_url' => null,
                'is_active' => true,
                'sort_order' => 9,
            ],
            [
                'code' => 'yamato',
                'name' => ['ko' => '야마토운수', 'en' => 'Yamato Transport'],
                'type' => 'international',
                'tracking_url' => null,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'sagawa',
                'name' => ['ko' => '사가와익스프레스', 'en' => 'Sagawa Express'],
                'type' => 'international',
                'tracking_url' => null,
                'is_active' => true,
                'sort_order' => 11,
            ],

            // 기타
            [
                'code' => 'other',
                'name' => ['ko' => '기타', 'en' => 'Other'],
                'type' => 'domestic',
                'tracking_url' => null,
                'is_active' => true,
                'sort_order' => 99,
            ],
        ];
    }
}
