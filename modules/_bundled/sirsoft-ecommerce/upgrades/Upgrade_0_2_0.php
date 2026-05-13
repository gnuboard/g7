<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * v0.2.0 업그레이드 스텝
 *
 * - 배송사 마스터 테이블 초기 데이터 시딩
 * - 기존 주문의 carrier_name → carrier_id 역매핑
 * - 레이아웃 캐시 클리어
 */
class Upgrade_0_2_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->seedShippingCarriers($context);
        $this->mapOrderCarrierIds($context);
        $this->clearLayoutCache($context);
    }

    /**
     * 배송사 초기 데이터를 시딩합니다.
     *
     * 기존 데이터가 있으면 건너뛰고, 없는 배송사만 생성합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function seedShippingCarriers(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_shipping_carriers')) {
            $context->logger->warning('[v0.2.0] ecommerce_shipping_carriers 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        $carriers = [
            ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'hanjin', 'name' => ['ko' => '한진택배', 'en' => 'Hanjin Express'], 'type' => 'domestic', 'tracking_url' => 'https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'lotte', 'name' => ['ko' => '롯데택배', 'en' => 'Lotte Global Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'logen', 'name' => ['ko' => '로젠택배', 'en' => 'Logen Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://www.ilogen.com/web/personal/trace/{tracking_number}', 'is_active' => true, 'sort_order' => 4],
            ['code' => 'ups', 'name' => ['ko' => 'UPS', 'en' => 'UPS'], 'type' => 'international', 'tracking_url' => 'https://www.ups.com/track?tracknum={tracking_number}', 'is_active' => true, 'sort_order' => 5],
            ['code' => 'ems', 'name' => ['ko' => 'EMS', 'en' => 'EMS'], 'type' => 'international', 'tracking_url' => 'https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}', 'is_active' => true, 'sort_order' => 6],
            ['code' => 'dhl', 'name' => ['ko' => 'DHL', 'en' => 'DHL'], 'type' => 'international', 'tracking_url' => 'https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}', 'is_active' => true, 'sort_order' => 7],
            ['code' => 'fedex', 'name' => ['ko' => 'FedEx', 'en' => 'FedEx'], 'type' => 'international', 'tracking_url' => 'https://www.fedex.com/fedextrack/?tracknumbers={tracking_number}', 'is_active' => true, 'sort_order' => 8],
            ['code' => 'sf', 'name' => ['ko' => 'SF Express', 'en' => 'SF Express'], 'type' => 'international', 'tracking_url' => null, 'is_active' => true, 'sort_order' => 9],
            ['code' => 'yamato', 'name' => ['ko' => '야마토운수', 'en' => 'Yamato Transport'], 'type' => 'international', 'tracking_url' => null, 'is_active' => true, 'sort_order' => 10],
            ['code' => 'sagawa', 'name' => ['ko' => '사가와익스프레스', 'en' => 'Sagawa Express'], 'type' => 'international', 'tracking_url' => null, 'is_active' => true, 'sort_order' => 11],
            ['code' => 'other', 'name' => ['ko' => '기타', 'en' => 'Other'], 'type' => 'domestic', 'tracking_url' => null, 'is_active' => true, 'sort_order' => 99],
        ];

        $created = 0;
        foreach ($carriers as $carrier) {
            $result = ShippingCarrier::firstOrCreate(
                ['code' => $carrier['code']],
                $carrier
            );

            if ($result->wasRecentlyCreated) {
                $created++;
            }
        }

        $context->logger->info("[v0.2.0] 배송사 시딩 완료: {$created}건 생성 (총 " . count($carriers) . '건 중)');
    }

    /**
     * 기존 주문의 carrier_name → carrier_id 역매핑을 수행합니다.
     *
     * carrier_id가 null이고 carrier_name이 있는 주문 배송 레코드에 대해
     * ecommerce_shipping_carriers 테이블의 name->>'$.ko' 값으로 매칭합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function mapOrderCarrierIds(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_order_shippings') || ! Schema::hasTable('ecommerce_shipping_carriers')) {
            return;
        }

        // carrier_name → carrier_id 매핑 테이블 구성
        $carriers = ShippingCarrier::all();
        $nameToIdMap = [];

        foreach ($carriers as $carrier) {
            $name = $carrier->name;
            if (is_array($name)) {
                // 한국어명으로 매핑
                if (! empty($name['ko'])) {
                    $nameToIdMap[$name['ko']] = $carrier->id;
                }
                // 영문명으로도 매핑 (fallback)
                if (! empty($name['en'])) {
                    $nameToIdMap[$name['en']] = $carrier->id;
                }
            }
        }

        if (empty($nameToIdMap)) {
            $context->logger->info('[v0.2.0] 배송사 데이터가 없어 주문 매핑을 건너뜁니다.');

            return;
        }

        // carrier_id가 null이고 carrier_name이 있는 주문 배송 레코드 조회
        $unmappedCount = DB::table('ecommerce_order_shippings')
            ->whereNull('carrier_id')
            ->whereNotNull('carrier_name')
            ->where('carrier_name', '!=', '')
            ->count();

        if ($unmappedCount === 0) {
            $context->logger->info('[v0.2.0] 매핑 대상 주문 배송 레코드가 없습니다.');

            return;
        }

        $mappedCount = 0;
        foreach ($nameToIdMap as $name => $carrierId) {
            $affected = DB::table('ecommerce_order_shippings')
                ->whereNull('carrier_id')
                ->where('carrier_name', $name)
                ->update(['carrier_id' => $carrierId]);

            $mappedCount += $affected;
        }

        $context->logger->info("[v0.2.0] 주문 배송 carrier_id 매핑 완료: {$mappedCount}/{$unmappedCount}건");
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * carrier 관련 UI 변경이 캐시에 반영되도록 합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.2.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.2.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
