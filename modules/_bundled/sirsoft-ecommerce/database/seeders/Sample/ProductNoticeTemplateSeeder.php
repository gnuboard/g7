<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;

/**
 * 상품정보제공고시 템플릿 시더
 *
 * 한국 전자상거래법(전자상거래 등에서의 상품 등의 정보제공에 관한 고시)에 따라
 * 품목별 필수 표시항목을 포함한 템플릿을 생성합니다.
 *
 * 기본값 규칙:
 * - 일반 정보 항목: "상세페이지 참조"
 * - A/S/고객서비스: "고객센터 문의"
 * - 품질보증기준: "제품 이상 시 공정거래위원회 고시에 따라 보상"
 * - 선택적 항목(GMO, 기능성 등): "해당 사항 없음"
 */
class ProductNoticeTemplateSeeder extends Seeder
{
    /**
     * 공통 기본값 상수
     */
    private const DEFAULT_SEE_PAGE = ['ko' => '상세페이지 참조', 'en' => 'See product page'];
    private const DEFAULT_CS_CONTACT = ['ko' => '고객센터 문의', 'en' => 'Contact customer service'];
    private const DEFAULT_WARRANTY = ['ko' => '제품 이상 시 공정거래위원회 고시에 따라 보상', 'en' => 'Compensation per FTC guidelines for defective products'];
    private const DEFAULT_NA = ['ko' => '해당 사항 없음', 'en' => 'N/A'];

    /**
     * 템플릿 데이터
     *
     * @var array<int, array{name: array{ko: string, en: string}, category: string, fields: array, sort_order: int}>
     */
    private array $templates = [];

    /**
     * 생성자에서 템플릿 데이터 초기화
     */
    public function __construct()
    {
        $this->templates = $this->getTemplates();
    }

    /**
     * 시더 실행
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('상품정보제공고시 템플릿 데이터 생성을 시작합니다.');

        $this->deleteExisting();
        $this->createTemplates();

        $count = ProductNoticeTemplate::count();
        $this->command->info("상품정보제공고시 템플릿 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 템플릿 삭제
     *
     * @return void
     */
    private function deleteExisting(): void
    {
        $deletedCount = ProductNoticeTemplate::count();

        if ($deletedCount > 0) {
            ProductNoticeTemplate::query()->delete();
            $this->command->warn("기존 템플릿 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 템플릿 생성
     *
     * @return void
     */
    private function createTemplates(): void
    {
        // 1. 법정 19개 템플릿 생성
        foreach ($this->templates as $templateData) {
            ProductNoticeTemplate::create([
                'name' => $templateData['name'],
                'category' => $templateData['category'],
                'fields' => $templateData['fields'],
                'is_active' => true,
                'sort_order' => $templateData['sort_order'],
            ]);

            $this->command->line("  - 템플릿 생성: {$templateData['name']['ko']} ({$templateData['name']['en']})");
        }

        // 2. 테스트용 템플릿 38개 추가 (총 57개)
        $this->createTestTemplates(38);
    }

    /**
     * 테스트용 템플릿 생성
     *
     * @param int $count 생성할 테스트 템플릿 수
     * @return void
     */
    private function createTestTemplates(int $count): void
    {
        $baseFields = [
            ['name' => ['ko' => '항목1', 'en' => 'Field 1'], 'content' => self::DEFAULT_SEE_PAGE],
            ['name' => ['ko' => '항목2', 'en' => 'Field 2'], 'content' => self::DEFAULT_SEE_PAGE],
            ['name' => ['ko' => '항목3', 'en' => 'Field 3'], 'content' => self::DEFAULT_SEE_PAGE],
        ];

        for ($i = 1; $i <= $count; $i++) {
            ProductNoticeTemplate::create([
                'name' => [
                    'ko' => "테스트{$i}",
                    'en' => "Test{$i}",
                ],
                'category' => "test-{$i}",
                'fields' => $baseFields,
                'is_active' => true,
                'sort_order' => 19 + $i,
            ]);

            $this->command->line("  - 템플릿 생성: 테스트{$i} (Test{$i})");
        }
    }

    /**
     * 법정 19개 품목 템플릿 데이터 반환
     *
     * @return array<int, array{name: array{ko: string, en: string}, category: string, fields: array, sort_order: int}>
     */
    private function getTemplates(): array
    {
        return [
            // 1. 의류
            [
                'name' => ['ko' => '의류', 'en' => 'Clothing'],
                'category' => 'clothing',
                'fields' => [
                    ['name' => ['ko' => '제품 소재 (섬유의 조성 또는 혼용률)', 'en' => 'Material Composition'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '치수', 'en' => 'Size'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '세탁방법 및 취급시 주의사항', 'en' => 'Care Instructions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월', 'en' => 'Manufacturing Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 1,
            ],
            // 2. 구두/신발
            [
                'name' => ['ko' => '구두/신발', 'en' => 'Shoes'],
                'category' => 'shoes',
                'fields' => [
                    ['name' => ['ko' => '제품 주소재 (겉감/안감)', 'en' => 'Main Material (Outer/Inner)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '치수 (발 길이)', 'en' => 'Size (Foot Length)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '굽 높이', 'en' => 'Heel Height'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '취급시 주의사항', 'en' => 'Care Instructions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 2,
            ],
            // 3. 가방
            [
                'name' => ['ko' => '가방', 'en' => 'Bags'],
                'category' => 'bags',
                'fields' => [
                    ['name' => ['ko' => '종류', 'en' => 'Type'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기 (가로×세로×폭)', 'en' => 'Dimensions (W×H×D)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월', 'en' => 'Manufacturing Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '취급시 주의사항', 'en' => 'Care Instructions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 3,
            ],
            // 4. 패션잡화
            [
                'name' => ['ko' => '패션잡화 (모자/벨트/액세서리)', 'en' => 'Fashion Accessories'],
                'category' => 'fashion-accessories',
                'fields' => [
                    ['name' => ['ko' => '종류', 'en' => 'Type'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '치수', 'en' => 'Size'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월', 'en' => 'Manufacturing Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '취급시 주의사항', 'en' => 'Care Instructions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 4,
            ],
            // 5. 침구류/커튼
            [
                'name' => ['ko' => '침구류/커튼', 'en' => 'Bedding/Curtains'],
                'category' => 'bedding',
                'fields' => [
                    ['name' => ['ko' => '제품 소재 (충전재 포함)', 'en' => 'Material (Including Filling)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '치수', 'en' => 'Dimensions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제품 구성', 'en' => 'Product Composition'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '세탁방법 및 취급시 주의사항', 'en' => 'Care Instructions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 5,
            ],
            // 6. 가구
            [
                'name' => ['ko' => '가구 (침대/소파/싱크대/DIY제품)', 'en' => 'Furniture'],
                'category' => 'furniture',
                'fields' => [
                    ['name' => ['ko' => '품명', 'en' => 'Product Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '구성품', 'en' => 'Components'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '주요 소재', 'en' => 'Main Material'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기', 'en' => 'Dimensions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '배송/설치 비용', 'en' => 'Delivery/Installation Cost'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 6,
            ],
            // 7. 영상가전
            [
                'name' => ['ko' => '영상가전 (TV류)', 'en' => 'Video Appliances'],
                'category' => 'video-appliances',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '정격전압/소비전력', 'en' => 'Rated Voltage/Power'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '에너지소비효율등급', 'en' => 'Energy Efficiency Rating'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '동일모델 출시년월', 'en' => 'Release Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기', 'en' => 'Dimensions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '화면 사양 (해상도, 밝기 등)', 'en' => 'Display Specifications'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 7,
            ],
            // 8. 가정용 전기제품
            [
                'name' => ['ko' => '가정용 전기제품 (냉장고/세탁기/식기세척기 등)', 'en' => 'Home Electronics'],
                'category' => 'home-electronics',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '정격전압/소비전력', 'en' => 'Rated Voltage/Power'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '에너지소비효율등급', 'en' => 'Energy Efficiency Rating'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '동일모델 출시년월', 'en' => 'Release Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기 (용량)', 'en' => 'Dimensions (Capacity)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 8,
            ],
            // 9. 계절가전
            [
                'name' => ['ko' => '계절가전 (에어컨/히터 등)', 'en' => 'Seasonal Appliances'],
                'category' => 'seasonal-appliances',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '정격전압/소비전력', 'en' => 'Rated Voltage/Power'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '에너지소비효율등급', 'en' => 'Energy Efficiency Rating'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '동일모델 출시년월', 'en' => 'Release Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기 (냉난방 면적)', 'en' => 'Dimensions (Coverage Area)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 9,
            ],
            // 10. 휴대폰
            [
                'name' => ['ko' => '휴대폰', 'en' => 'Mobile Phones'],
                'category' => 'mobile-phones',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '동일모델 출시년월', 'en' => 'Release Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기/무게', 'en' => 'Dimensions/Weight'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '이동통신사', 'en' => 'Mobile Carrier'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '주요 사양', 'en' => 'Main Specifications'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 10,
            ],
            // 11. 컴퓨터/주변기기
            [
                'name' => ['ko' => '컴퓨터 및 주변기기', 'en' => 'Computers & Peripherals'],
                'category' => 'computers',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'KC 인증 필 유무', 'en' => 'KC Certification'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '정격전압/소비전력', 'en' => 'Rated Voltage/Power'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '동일모델 출시년월', 'en' => 'Release Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기/무게', 'en' => 'Dimensions/Weight'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '주요 사양', 'en' => 'Main Specifications'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 11,
            ],
            // 12. 화장품
            [
                'name' => ['ko' => '화장품', 'en' => 'Cosmetics'],
                'category' => 'cosmetics',
                'fields' => [
                    ['name' => ['ko' => '용량 또는 중량', 'en' => 'Volume/Weight'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제품 주요 사양 (피부타입)', 'en' => 'Product Specifications'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '사용기한 또는 개봉 후 사용기간', 'en' => 'Expiration/Usage Period'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '사용방법', 'en' => 'How to Use'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '주요 성분', 'en' => 'Main Ingredients'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '기능성 화장품 여부', 'en' => 'Functional Cosmetics'], 'content' => self::DEFAULT_NA],
                    ['name' => ['ko' => '사용시 주의사항', 'en' => 'Precautions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => '소비자상담 전화번호', 'en' => 'Customer Service Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 12,
            ],
            // 13. 귀금속/보석/시계
            [
                'name' => ['ko' => '귀금속/보석/시계', 'en' => 'Jewelry/Watches'],
                'category' => 'jewelry',
                'fields' => [
                    ['name' => ['ko' => '소재/순도/밴드재질 (시계)', 'en' => 'Material/Purity/Band'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '중량', 'en' => 'Weight'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '치수', 'en' => 'Dimensions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '착용 시 주의사항', 'en' => 'Wearing Precautions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '귀금속/보석류 등급', 'en' => 'Grade'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 13,
            ],
            // 14. 식품 - 농수축산물
            [
                'name' => ['ko' => '식품 (농수축산물)', 'en' => 'Food (Agricultural)'],
                'category' => 'food-agricultural',
                'fields' => [
                    ['name' => ['ko' => '품목 또는 명칭', 'en' => 'Product Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '포장단위별 용량(중량), 수량', 'en' => 'Package Size/Quantity'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '생산자/수입자', 'en' => 'Producer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '원산지', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월일 (포장일)', 'en' => 'Manufacturing/Packing Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '농산물 표준규격 등급', 'en' => 'Agricultural Standards Grade'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '유전자변형 농산물 여부', 'en' => 'GMO Status'], 'content' => self::DEFAULT_NA],
                    ['name' => ['ko' => '상품 구성', 'en' => 'Product Composition'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '보관방법 또는 취급방법', 'en' => 'Storage/Handling'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소비자상담 전화번호', 'en' => 'Customer Service Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 14,
            ],
            // 15. 가공식품
            [
                'name' => ['ko' => '가공식품', 'en' => 'Processed Food'],
                'category' => 'processed-food',
                'fields' => [
                    ['name' => ['ko' => '식품의 유형', 'en' => 'Food Type'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '생산자 및 소재지', 'en' => 'Producer/Location'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월일, 소비기한', 'en' => 'Mfg Date/Expiration'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '포장단위별 내용물의 용량(중량)', 'en' => 'Package Content'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '원재료명 및 함량', 'en' => 'Ingredients/Content'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '영양성분', 'en' => 'Nutrition Facts'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '유전자변형식품 여부', 'en' => 'GMO Status'], 'content' => self::DEFAULT_NA],
                    ['name' => ['ko' => '수입품 여부', 'en' => 'Import Status'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소비자안전을 위한 주의사항', 'en' => 'Safety Precautions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소비자상담 전화번호', 'en' => 'Customer Service Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 15,
            ],
            // 16. 건강기능식품
            [
                'name' => ['ko' => '건강기능식품', 'en' => 'Health Supplements'],
                'category' => 'health-supplements',
                'fields' => [
                    ['name' => ['ko' => '식품의 유형', 'en' => 'Food Type'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조업소 명칭 및 소재지', 'en' => 'Manufacturer/Location'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조연월일, 소비기한', 'en' => 'Mfg Date/Expiration'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '포장단위별 내용물의 용량(중량)', 'en' => 'Package Content'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '원재료명 및 함량', 'en' => 'Ingredients/Content'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '영양정보', 'en' => 'Nutrition Info'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '기능정보', 'en' => 'Function Info'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '섭취량, 섭취방법 및 섭취 시 주의사항', 'en' => 'Dosage/Method/Precautions'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '소비자상담 전화번호', 'en' => 'Customer Service Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 16,
            ],
            // 17. 스포츠용품
            [
                'name' => ['ko' => '스포츠용품', 'en' => 'Sports Equipment'],
                'category' => 'sports-equipment',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기/중량', 'en' => 'Size/Weight'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '색상', 'en' => 'Color'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '재질', 'en' => 'Material'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '상품별 세부 사양', 'en' => 'Detailed Specifications'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '품질보증기준', 'en' => 'Quality Assurance Standards'], 'content' => self::DEFAULT_WARRANTY],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 17,
            ],
            // 18. 서적
            [
                'name' => ['ko' => '서적', 'en' => 'Books'],
                'category' => 'books',
                'fields' => [
                    ['name' => ['ko' => '도서명', 'en' => 'Book Title'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '저자, 출판사', 'en' => 'Author/Publisher'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '크기 (판형)', 'en' => 'Size (Format)'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '쪽수', 'en' => 'Pages'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제품 구성 (전집의 경우)', 'en' => 'Product Composition'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '출간일', 'en' => 'Publication Date'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '목차 또는 책소개', 'en' => 'Table of Contents/Summary'], 'content' => self::DEFAULT_SEE_PAGE],
                ],
                'sort_order' => 18,
            ],
            // 19. 기타 재화
            [
                'name' => ['ko' => '기타 재화', 'en' => 'Other Goods'],
                'category' => 'other-goods',
                'fields' => [
                    ['name' => ['ko' => '품명 및 모델명', 'en' => 'Product/Model Name'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '법에 의한 인증·허가 등을 받았음을 확인할 수 있는 경우 그에 대한 사항', 'en' => 'Certifications/Permits'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조국 또는 원산지', 'en' => 'Country of Origin'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => '제조자/수입자', 'en' => 'Manufacturer/Importer'], 'content' => self::DEFAULT_SEE_PAGE],
                    ['name' => ['ko' => 'A/S 책임자와 전화번호', 'en' => 'A/S Contact'], 'content' => self::DEFAULT_CS_CONTACT],
                ],
                'sort_order' => 19,
            ],
        ];
    }
}
