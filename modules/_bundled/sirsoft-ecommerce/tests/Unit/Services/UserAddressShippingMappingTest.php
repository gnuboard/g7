<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\UserAddressService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserAddressService::mapShippingInfoToAddressData — 국가별 명시 매핑 테스트 (B4)
 *
 * 체크아웃/주문 제출 배송지 정보를 UserAddress 컬럼 구조로 국가별 명시 매핑.
 * - KR: zipcode/address/address_detail 만, 해외 필드 제거
 * - 그 외: intl_* → city/state/postal_code 변환, 국내 필드 제거
 */
class UserAddressShippingMappingTest extends ModuleTestCase
{
    private function service(): UserAddressService
    {
        return app(UserAddressService::class);
    }

    public function test_domestic_kr_maps_only_domestic_fields(): void
    {
        $data = $this->service()->mapShippingInfoToAddressData(1, '집', [
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'country_code' => 'KR',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '101호',
            // 해외 필드가 섞여 들어와도 KR 이면 제거되어야 함
            'intl_city' => 'New York',
            'address_line_1' => '123 Main St',
        ]);

        $this->assertSame('KR', $data['country_code']);
        $this->assertSame('12345', $data['zipcode']);
        $this->assertSame('서울시 강남구', $data['address']);
        $this->assertSame('101호', $data['address_detail']);
        $this->assertArrayNotHasKey('city', $data);
        $this->assertArrayNotHasKey('address_line_1', $data);
        $this->assertArrayNotHasKey('intl_city', $data);
    }

    public function test_international_maps_intl_fields_to_columns(): void
    {
        $data = $this->service()->mapShippingInfoToAddressData(1, 'Office', [
            'recipient_name' => 'John Doe',
            'recipient_phone' => '010-1111-2222',
            'country_code' => 'US',
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Apt 4',
            'intl_city' => 'New York',
            'intl_state' => 'NY',
            'intl_postal_code' => '10001',
            // 국내 필드가 섞여 들어와도 해외면 제거되어야 함
            'zipcode' => '99999',
            'address' => '국내주소',
        ]);

        $this->assertSame('US', $data['country_code']);
        $this->assertSame('123 Main St', $data['address_line_1']);
        $this->assertSame('Apt 4', $data['address_line_2']);
        // intl_* → city/state/postal_code 변환
        $this->assertSame('New York', $data['city']);
        $this->assertSame('NY', $data['state']);
        $this->assertSame('10001', $data['postal_code']);
        // 국내 전용 필드 제거
        $this->assertArrayNotHasKey('zipcode', $data);
        $this->assertArrayNotHasKey('address', $data);
        $this->assertArrayNotHasKey('intl_city', $data);
    }

    public function test_missing_country_code_defaults_to_kr(): void
    {
        $data = $this->service()->mapShippingInfoToAddressData(1, '집', [
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울',
        ]);

        $this->assertSame('KR', $data['country_code']);
        $this->assertSame('12345', $data['zipcode']);
    }
}
