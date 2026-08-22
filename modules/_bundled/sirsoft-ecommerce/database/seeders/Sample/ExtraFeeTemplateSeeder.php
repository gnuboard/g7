<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;

/**
 * 도서산간 추가배송비 템플릿 시더
 *
 * 출처: https://imweb.me/faq?mode=view&category=29&category2=40&idx=71671
 */
class ExtraFeeTemplateSeeder extends Seeder
{
    /**
     * 추가배송비 템플릿 시더를 실행합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('도서산간 추가배송비 템플릿 데이터 생성을 시작합니다.');

        $this->deleteExistingTemplates();

        $templates = $this->getTemplatesData();

        foreach ($templates as $templateData) {
            ExtraFeeTemplate::create($templateData);
        }

        $count = ExtraFeeTemplate::count();
        $this->command->info("도서산간 추가배송비 템플릿 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 템플릿 데이터를 삭제합니다.
     *
     * @return void
     */
    private function deleteExistingTemplates(): void
    {
        $deletedCount = ExtraFeeTemplate::count();

        if ($deletedCount > 0) {
            ExtraFeeTemplate::query()->delete();
            $this->command->line("  - 기존 추가배송비 템플릿 {$deletedCount}건 삭제 완료");
        }
    }

    /**
     * 도서산간 추가배송비 템플릿 데이터를 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTemplatesData(): array
    {
        $fee = 3000;
        $description = '도서산간 지역';

        return [
            ['zipcode' => '63000-63365', 'fee' => $fee, 'region' => '제주 제주시', 'description' => $description, 'is_active' => true],
            ['zipcode' => '63500-63644', 'fee' => $fee, 'region' => '제주 서귀포시', 'description' => $description, 'is_active' => true],
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
}
