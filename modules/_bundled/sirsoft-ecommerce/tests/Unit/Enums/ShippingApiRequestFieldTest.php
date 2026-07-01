<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송 계산 API 요청 참고 필드 enum 테스트 (A13 W3)
 */
class ShippingApiRequestFieldTest extends ModuleTestCase
{
    public function test_values_returns_five_candidates(): void
    {
        $values = ShippingApiRequestField::values();

        $this->assertSame(
            ['policy_id', 'country_code', 'items', 'group_total', 'total_quantity'],
            $values,
        );
    }

    public function test_options_have_value_and_translated_label(): void
    {
        $options = ShippingApiRequestField::options();

        $this->assertCount(5, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            // 라벨은 백엔드 SSoT 에서 번역되어 내려감 — 미해석 키 원문이 아니어야 한다
            $this->assertNotEmpty($option['label']);
            $this->assertStringNotContainsString('shipping_api_request_field', $option['label']);
        }
    }

    public function test_label_returns_translated_string_per_case(): void
    {
        // ko 로케일 기준 — enums.php SSoT 의 한국어 라벨과 일치
        $this->assertSame('배송정책 ID', ShippingApiRequestField::POLICY_ID->label());
        $this->assertSame('국가 코드', ShippingApiRequestField::COUNTRY_CODE->label());
        $this->assertSame('주문 항목', ShippingApiRequestField::ITEMS->label());
        $this->assertSame('그룹 합계 금액', ShippingApiRequestField::GROUP_TOTAL->label());
        $this->assertSame('총 수량', ShippingApiRequestField::TOTAL_QUANTITY->label());
    }

    public function test_values_match_calculation_request_data_keys(): void
    {
        // OrderCalculationService::calculateApiShippingFee() $requestData 키와 1:1 일치
        $requestDataKeys = ['policy_id', 'country_code', 'items', 'group_total', 'total_quantity'];

        $this->assertSame($requestDataKeys, ShippingApiRequestField::values());
    }
}
