<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

class ShippingCarrierSeeder extends Seeder
{
    /**
     * 배송사 초기 데이터를 생성합니다.
     */
    public function run(): void
    {
        $this->command->info('배송사 초기 데이터 생성을 시작합니다.');

        $this->deleteExistingCarriers();

        $carriers = [
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

        foreach ($carriers as $carrier) {
            ShippingCarrier::create($carrier);
            $this->command->line("  - 배송사 생성: {$carrier['name']['ko']} ({$carrier['code']})");
        }

        $count = ShippingCarrier::count();
        $this->command->info("배송사 초기 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 배송사 데이터를 삭제합니다.
     */
    private function deleteExistingCarriers(): void
    {
        $deletedCount = ShippingCarrier::count();

        if ($deletedCount > 0) {
            ShippingCarrier::query()->delete();
            $this->command->warn("기존 배송사 {$deletedCount}건을 삭제했습니다.");
        }
    }
}
