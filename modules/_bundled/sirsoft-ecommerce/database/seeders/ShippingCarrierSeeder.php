<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;

/**
 * 배송사 초기 데이터 시더.
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
 * 활성 언어팩의 seed/shipping_carriers.json 다국어 키는 trait 가 자동 머지.
 */
class ShippingCarrierSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-ecommerce';
    }

    public function getTranslatableEntity(): string
    {
        return 'shipping_carriers';
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

    public function run(): void
    {
        $this->command->info('배송사 초기 데이터 동기화를 시작합니다.');

        $helper = app(GenericEntitySyncHelper::class);
        $created = 0;
        $synced = 0;
        $codes = [];

        foreach ($this->resolveTranslatedDefaults() as $carrier) {
            $existing = ShippingCarrier::where('code', $carrier['code'])->exists();

            // 시더가 새로 만드는 행은 created_by 를 명시적으로 비운다(stale 정리 범위가 `created_by IS NULL` 이다).
            $helper->sync(
                ShippingCarrier::class,
                ['code' => $carrier['code']],
                $existing ? $carrier : $carrier + ['created_by' => null],
            );
            $codes[] = $carrier['code'];

            if ($existing) {
                $synced++;
            } else {
                $created++;
                $this->command->line("  - 배송사 생성: {$carrier['name']['ko']} ({$carrier['code']})");
            }
        }

        // stale 정리는 **시드 정의에서 빠진 코드**를 걷어내는 장치다. 그런데 이 표는 운영자가
        // 설정 배송 탭에서 직접 배송사를 추가·수정하는 표이기도 하다 — 운영자가 만든 배송사는
        // 정의상 이 목록에 없으므로, 보호 없이 돌리면 재설치 한 번이 사용 중인 것까지 지운다.
        // 설정 화면의 삭제 경로는 사용 중이면 400 으로 막는데(`ShippingCarrierService`), 이 경로만
        // 그 판정을 거치지 않으면 같은 표에 **강도가 다른 두 삭제 경로**가 생긴다.
        // 배송사를 붙잡는 외래키는 전부 `nullOnDelete` 라, 지워지는 순간 접수된 교환·반품
        // 신청서의 회수 택배사가 오류도 로그도 없이 비워진다.
        //
        // 판정은 그 서비스(단일 출처)에서 받고 삭제는 그대로 Helper 가 한다. 사용 중인 코드를
        // 보존 목록에 더하는 형태라 Helper 시그니처를 바꾸지 않는다
        // (`ClaimReasonSeeder` 와 같은 형태).
        $service = app(ShippingCarrierService::class);

        $inUseCodes = ShippingCarrier::query()
            ->whereNotIn('code', $codes)
            ->get()
            ->filter(fn (ShippingCarrier $carrier) => $service->countUsage($carrier->id) > 0)
            ->pluck('code')
            ->all();

        // 정리 대상은 시더가 만든 행(created_by IS NULL)뿐이다 — 운영자가 만든 미사용 택배사는 남긴다(#727 W7).
        $deleted = $helper->cleanupStale(
            ShippingCarrier::class,
            ['created_by' => null],
            'code',
            [...$codes, ...$inUseCodes],
        );

        $preserved = count($inUseCodes);
        $total = ShippingCarrier::count();
        $this->command->info("배송사 동기화 완료: {$created}건 생성, {$synced}건 동기화, stale {$deleted}건 삭제, 사용 중 {$preserved}건 보존 (전체 {$total}건)");
    }
}
