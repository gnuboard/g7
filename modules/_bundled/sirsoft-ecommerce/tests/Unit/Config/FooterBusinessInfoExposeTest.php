<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Config;

use App\Traits\FiltersFrontendSchema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 푸터 사업자 정보 노출 테스트 (#434)
 *
 * 쇼핑몰이 사용자 템플릿 공용 자리(user_global_footer)에 사업자 정보를 그리려면 법정 표시 항목이 프론트 설정
 * (`_global.modules['sirsoft-ecommerce'].basic_info`)에 실려야 한다. 표시 의무가 없는 항목(업종·업태·내부 설정)은 계속 숨긴다.
 */
class FooterBusinessInfoExposeTest extends ModuleTestCase
{
    /**
     * 선언 파일을 읽습니다.
     *
     * @return array<string, mixed> 선언
     */
    private function declaration(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../config/settings/defaults.json'), true);
    }

    /**
     * 노출 스키마로 거른 기본정보.
     *
     * @param  array<string, mixed>  $basicInfo  저장값
     * @return array<string, mixed> 노출되는 기본정보
     */
    private function exposed(array $basicInfo): array
    {
        $filter = new class
        {
            use FiltersFrontendSchema;

            /**
             * 노출 스키마로 거릅니다(트레이트의 보호 메서드를 시험에서 부르기 위한 통로).
             *
             * @param  array<string, mixed>  $settings  설정
             * @param  array<string, mixed>  $schema  노출 스키마
             * @return array<string, mixed> 노출 설정
             */
            public function run(array $settings, array $schema): array
            {
                return $this->filterByFrontendSchema($settings, $schema);
            }
        };

        return $filter->run(['basic_info' => $basicInfo], $this->declaration()['frontend_schema'])['basic_info'] ?? [];
    }

    /**
     * 기본값은 켜짐이고, 법정 표시 항목은 노출, 업종·업태·내부 설정은 비노출이다.
     *
     * @scenario case=expose
     *
     * @effects legal_fields_exposed_and_internal_hidden
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_legal_fields_are_exposed_and_internal_fields_hidden(): void
    {
        $defaults = $this->declaration()['defaults']['basic_info'];
        $this->assertTrue($defaults['footer_business_info_enabled']);

        $stored = array_merge($defaults, [
            'company_name' => '에스아이알', 'ceo_name' => '홍길동', 'business_number' => '123-45-67890',
            'mail_order_number' => '2026-서울-0001', 'telecom_number' => 'T-1', 'zipcode' => '01234', 'base_address' => '서울',
            'detail_address' => '1층', 'phone' => '02-000-0000', 'fax' => '02-000-0001', 'email' => 'cs@example.com',
            'privacy_officer' => '김보호', 'privacy_officer_email' => 'privacy@example.com',
            'business_type' => '도소매', 'business_category' => '전자상거래', 'shop_name' => '내부 이름',
        ]);

        $exposed = $this->exposed($stored);

        foreach (['company_name', 'ceo_name', 'business_number', 'mail_order_number', 'telecom_number', 'zipcode', 'base_address',
            'detail_address', 'phone', 'fax', 'email', 'privacy_officer', 'privacy_officer_email', 'footer_business_info_enabled',
            'route_path', 'no_route'] as $key) {
            $this->assertArrayHasKey($key, $exposed, $key.' 노출');
        }

        foreach (['business_type', 'business_category', 'shop_name', 'public_asset_disk', 'export_retention_days'] as $key) {
            $this->assertArrayNotHasKey($key, $exposed, $key.' 비노출');
        }
    }
}
