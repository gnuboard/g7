<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Config;

use App\Traits\FiltersFrontendSchema;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 푸터 사업자 정보 노출 테스트
 *
 * 쇼핑몰이 사용자 템플릿 공용 자리(user_global_footer)에 사업자 정보를 그리려면 조각이 그리는 항목이 프론트 설정
 * (`_global.modules['sirsoft-ecommerce'].basic_info`)에 실려야 한다. 조각이 그리지 않는 항목(상점 이름·업종·업태·내부 설정)은 계속 숨긴다.
 * 켜고 끄는 설정은 `show_business_footer`(기본 켜짐) 하나다 — 같은 뜻의 두 번째 토글(`footer_business_info_enabled`)을 두지 않는다.
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
     * 기본값은 켜짐(show_business_footer)이고, 조각이 그리는 항목은 노출, 상점 이름·업종·업태·내부 설정은 비노출이다.
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
        $this->assertTrue($defaults['show_business_footer']);
        $this->assertArrayNotHasKey('footer_business_info_enabled', $defaults, '켜기 설정은 show_business_footer 하나');
        $this->assertArrayNotHasKey('footer_business_info_enabled', $this->declaration()['frontend_schema']['basic_info']['fields']);

        $stored = array_merge($defaults, [
            'company_name' => '에스아이알', 'ceo_name' => '홍길동', 'business_number' => '123-45-67890',
            'mail_order_number' => '2026-서울-0001', 'telecom_number' => 'T-1', 'zipcode' => '01234', 'base_address' => '서울',
            'detail_address' => '1층', 'phone' => '02-000-0000', 'fax' => '02-000-0001', 'email' => 'cs@example.com',
            'privacy_officer' => '김보호', 'privacy_officer_email' => 'privacy@example.com', 'hosting_provider' => '시험 호스팅',
            'business_type' => '도소매', 'business_category' => '전자상거래', 'shop_name' => '내부 이름',
        ]);

        $exposed = $this->exposed($stored);

        foreach (['company_name', 'ceo_name', 'business_number', 'mail_order_number', 'telecom_number', 'zipcode', 'base_address',
            'detail_address', 'phone', 'fax', 'email', 'privacy_officer', 'privacy_officer_email', 'hosting_provider',
            'show_business_footer', 'route_path', 'no_route'] as $key) {
            $this->assertArrayHasKey($key, $exposed, $key.' 노출');
        }

        foreach (['business_type', 'business_category', 'shop_name', 'public_asset_disk', 'export_retention_days'] as $key) {
            $this->assertArrayNotHasKey($key, $exposed, $key.' 비노출');
        }
    }

    /**
     * 공개하는 사업자 칸과 「표시 끔」일 때 서버가 빼는 칸이 같다 — 공개만 하고 빼지 않는 칸이 생기면 끈 뒤에도 페이지 소스에 남는다.
     *
     * @scenario case=expose
     *
     * @effects exposed_business_fields_match_strip_list
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_exposed_business_fields_match_fields_stripped_when_hidden(): void
    {
        $fields = $this->declaration()['frontend_schema']['basic_info']['fields'];
        $exposed = array_keys(array_filter($fields, static fn (array $field): bool => (bool) ($field['expose'] ?? false)));
        $business = array_values(array_diff($exposed, ['route_path', 'no_route', 'show_business_footer']));
        sort($business);

        $stripped = EcommerceSettingsService::BUSINESS_FOOTER_FIELDS;
        sort($stripped);

        $this->assertSame($business, $stripped);
    }
}
