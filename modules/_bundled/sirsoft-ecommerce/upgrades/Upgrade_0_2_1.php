<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;

/**
 * v0.2.1 업그레이드 스텝
 *
 * - 도서산간 추가배송비 템플릿 초기 데이터 시딩 (34건)
 * - 레이아웃 캐시 클리어 (모달 CRUD 재설계 반영)
 */
class Upgrade_0_2_1 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->seedExtraFeeTemplates($context);
        $this->clearLayoutCache($context);
    }

    /**
     * 도서산간 추가배송비 템플릿 초기 데이터를 시딩합니다.
     *
     * 기존 데이터가 있으면 건너뛰고, 없는 우편번호만 생성합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function seedExtraFeeTemplates(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_shipping_policy_extra_fee_templates')) {
            $context->logger->warning('[v0.2.1] ecommerce_shipping_policy_extra_fee_templates 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        $templates = $this->getTemplatesData();

        $created = 0;
        foreach ($templates as $template) {
            $result = ExtraFeeTemplate::firstOrCreate(
                ['zipcode' => $template['zipcode']],
                $template
            );

            if ($result->wasRecentlyCreated) {
                $created++;
            }
        }

        $context->logger->info("[v0.2.1] 도서산간 추가배송비 템플릿 시딩 완료: {$created}건 생성 (총 " . count($templates) . '건 중)');
    }

    /**
     * 도서산간 추가배송비 템플릿 데이터를 반환합니다.
     *
     * 출처: https://imweb.me/faq?mode=view&category=29&category2=40&idx=71671
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTemplatesData(): array
    {
        $fee = 3000;
        $description = '도서산간 지역';

        return [
            ['zipcode' => '15654', 'fee' => $fee, 'region' => '경기 안산 풍도동', 'description' => $description, 'is_active' => true],
            ['zipcode' => '23008-23010', 'fee' => $fee, 'region' => '인천 강화 섬지역', 'description' => $description, 'is_active' => true],
            ['zipcode' => '23100-23116', 'fee' => $fee, 'region' => '인천 옹진 백령/대청/연평/북도면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '23124-23136', 'fee' => $fee, 'region' => '인천 옹진 자월/덕적면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '32133', 'fee' => $fee, 'region' => '충남 태안 섬지역', 'description' => $description, 'is_active' => true],
            ['zipcode' => '33411', 'fee' => $fee, 'region' => '충남 보령 섬지역', 'description' => $description, 'is_active' => true],
            ['zipcode' => '40200-40240', 'fee' => $fee, 'region' => '경북 울릉도', 'description' => $description, 'is_active' => true],
            ['zipcode' => '52570-52571', 'fee' => $fee, 'region' => '경남 사천 섬지역', 'description' => $description, 'is_active' => true],
            ['zipcode' => '53031-53033', 'fee' => $fee, 'region' => '경남 통영 용남면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '53088-53104', 'fee' => $fee, 'region' => '경남 통영 산양/한산/욕지/사량면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '54000', 'fee' => $fee, 'region' => '전북 군산 옥도면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '56347-56349', 'fee' => $fee, 'region' => '전북 부안 위도면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '57068-57069', 'fee' => $fee, 'region' => '전남 영광 낙월면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58760-58761', 'fee' => $fee, 'region' => '전남 목포 섬지역', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58800-58804', 'fee' => $fee, 'region' => '전남 신안 임자면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58809-58810', 'fee' => $fee, 'region' => '전남 신안 증도/지도', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58816-58818', 'fee' => $fee, 'region' => '전남 신안 지도/압해', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58826', 'fee' => $fee, 'region' => '전남 신안 압해', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58832', 'fee' => $fee, 'region' => '전남 신안 암태면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58839-58841', 'fee' => $fee, 'region' => '전남 신안 안좌면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58843-58866', 'fee' => $fee, 'region' => '전남 신안 비금/도초/하의/신의/장산/흑산면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '58953-58958', 'fee' => $fee, 'region' => '전남 진도 조도면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59102-59103', 'fee' => $fee, 'region' => '전남 완도 군외면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59127', 'fee' => $fee, 'region' => '전남 완도 군외면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59137-59145', 'fee' => $fee, 'region' => '전남 완도 금당/금일/생일면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59149-59170', 'fee' => $fee, 'region' => '전남 완도 청산/소안/노화/보길/군외/금일면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59421', 'fee' => $fee, 'region' => '전남 보성 벌교', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59531', 'fee' => $fee, 'region' => '전남 고흥 도화면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59551', 'fee' => $fee, 'region' => '전남 고흥 도양읍', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59563', 'fee' => $fee, 'region' => '전남 고흥 도양읍', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59568', 'fee' => $fee, 'region' => '전남 고흥 봉래면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59650', 'fee' => $fee, 'region' => '전남 여수 화정면', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59766', 'fee' => $fee, 'region' => '전남 여수 경호동', 'description' => $description, 'is_active' => true],
            ['zipcode' => '59781-59790', 'fee' => $fee, 'region' => '전남 여수 화정/남/삼산면', 'description' => $description, 'is_active' => true],
        ];
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * 모달 CRUD 재설계가 캐시에 반영되도록 합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.2.1] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.2.1] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
