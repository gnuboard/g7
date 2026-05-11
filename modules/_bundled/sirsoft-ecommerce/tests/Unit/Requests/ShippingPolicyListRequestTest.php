<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyListRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 목록 조회 요청 검증 테스트
 */
class ShippingPolicyListRequestTest extends ModuleTestCase
{
    /**
     * ShippingPolicyListRequest::rules() 는 ShippingType DB rows 를 Rule::in 값으로
     * 사용하므로 테스트 전에 시드 필요.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder::class);
    }

    /**
     * 검증 수행
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new ShippingPolicyListRequest();

        return Validator::make($data, $request->rules());
    }

    // ========================================
    // 기본 요청 테스트
    // ========================================

    public function test_valid_request_passes(): void
    {
        $validator = $this->validate([
            'search' => '택배',
            'shipping_methods' => ['parcel', 'quick'],
            'charge_policies' => ['fixed', 'conditional_free'],
            'countries' => ['KR', 'US'],
            'is_active' => 'true',
            'sort_by' => 'created_at',
            'sort_order' => 'desc',
            'per_page' => 20,
            'page' => 1,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_empty_request_passes(): void
    {
        // 모든 필드가 optional이므로 빈 요청도 통과해야 함
        $validator = $this->validate([]);

        $this->assertFalse($validator->fails());
    }

    // ========================================
    // search 필드 테스트
    // ========================================

    public function test_valid_search_passes(): void
    {
        $validator = $this->validate(['search' => '기본택배']);

        $this->assertFalse($validator->fails());
    }

    public function test_search_max_length_fails(): void
    {
        $validator = $this->validate(['search' => str_repeat('a', 201)]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('search', $validator->errors()->toArray());
    }

    public function test_search_at_max_length_passes(): void
    {
        $validator = $this->validate(['search' => str_repeat('a', 200)]);

        $this->assertFalse($validator->fails());
    }

    // ========================================
    // shipping_methods 필드 테스트
    // ========================================

    public function test_valid_shipping_methods_passes(): void
    {
        // ShippingTypeSeeder 가 시딩하는 코드들 사용 (parcel/direct/quick/freight/pickup/express/cvs 등)
        $validator = $this->validate([
            'shipping_methods' => ['parcel', 'quick', 'direct', 'pickup'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_single_shipping_method_passes(): void
    {
        $validator = $this->validate([
            'shipping_methods' => ['parcel'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_shipping_method_fails(): void
    {
        $validator = $this->validate([
            'shipping_methods' => ['invalid_method'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('shipping_methods.0', $validator->errors()->toArray());
    }

    public function test_mixed_valid_invalid_shipping_methods_fails(): void
    {
        $validator = $this->validate([
            'shipping_methods' => ['parcel', 'invalid_method'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('shipping_methods.1', $validator->errors()->toArray());
    }

    // ========================================
    // charge_policies 필드 테스트
    // ========================================

    public function test_valid_charge_policies_passes(): void
    {
        $validator = $this->validate([
            'charge_policies' => [
                'free',
                'fixed',
                'conditional_free',
                'range_amount',
                'range_quantity',
                'range_weight',
                'range_volume',
                'range_volume_weight',
                'api',
            ],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_single_charge_policy_passes(): void
    {
        $validator = $this->validate([
            'charge_policies' => ['fixed'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_charge_policy_fails(): void
    {
        $validator = $this->validate([
            'charge_policies' => ['invalid_policy'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('charge_policies.0', $validator->errors()->toArray());
    }

    // ========================================
    // countries 필드 테스트
    // ========================================

    public function test_valid_countries_passes(): void
    {
        $validator = $this->validate([
            'countries' => ['KR', 'US', 'CN', 'JP'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_single_country_passes(): void
    {
        $validator = $this->validate([
            'countries' => ['KR'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_unknown_country_passes(): void
    {
        // Settings 기반 동적 국가 지원으로 Enum 검증 제거 — 문자열 검증만 수행
        $validator = $this->validate([
            'countries' => ['XX'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_lowercase_country_passes(): void
    {
        // Settings 기반 동적 국가 지원으로 대소문자 제약 없음
        $validator = $this->validate([
            'countries' => ['kr'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_country_exceeding_max_length_fails(): void
    {
        // 국가 코드는 최대 10자
        $validator = $this->validate([
            'countries' => ['ABCDEFGHIJK'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('countries.0', $validator->errors()->toArray());
    }

    // ========================================
    // is_active 필드 테스트
    // ========================================

    public function test_is_active_true_passes(): void
    {
        $validator = $this->validate(['is_active' => 'true']);

        $this->assertFalse($validator->fails());
    }

    public function test_is_active_false_passes(): void
    {
        $validator = $this->validate(['is_active' => 'false']);

        $this->assertFalse($validator->fails());
    }

    public function test_is_active_empty_string_passes(): void
    {
        // 빈 문자열은 '전체' 필터를 의미
        $validator = $this->validate(['is_active' => '']);

        $this->assertFalse($validator->fails());
    }

    public function test_is_active_invalid_value_fails(): void
    {
        $validator = $this->validate(['is_active' => 'yes']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
    }

    public function test_is_active_integer_fails(): void
    {
        $validator = $this->validate(['is_active' => '1']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
    }

    // ========================================
    // sort_by 필드 테스트
    // ========================================

    public function test_valid_sort_by_fields_pass(): void
    {
        $validFields = [
            'id', 'name', 'is_active', 'sort_order',
            'created_at', 'updated_at',
        ];

        foreach ($validFields as $field) {
            $validator = $this->validate(['sort_by' => $field]);
            $this->assertFalse($validator->fails(), "sort_by '{$field}' should pass");
        }
    }

    public function test_invalid_sort_by_fails(): void
    {
        $validator = $this->validate(['sort_by' => 'invalid_field']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sort_by', $validator->errors()->toArray());
    }

    // ========================================
    // sort_order 필드 테스트
    // ========================================

    public function test_sort_order_asc_passes(): void
    {
        $validator = $this->validate(['sort_order' => 'asc']);

        $this->assertFalse($validator->fails());
    }

    public function test_sort_order_desc_passes(): void
    {
        $validator = $this->validate(['sort_order' => 'desc']);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_sort_order_fails(): void
    {
        $validator = $this->validate(['sort_order' => 'ascending']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sort_order', $validator->errors()->toArray());
    }

    // ========================================
    // per_page 필드 테스트
    // ========================================

    public function test_per_page_at_min_passes(): void
    {
        $validator = $this->validate(['per_page' => 10]);

        $this->assertFalse($validator->fails());
    }

    public function test_per_page_at_max_passes(): void
    {
        $validator = $this->validate(['per_page' => 100]);

        $this->assertFalse($validator->fails());
    }

    public function test_per_page_below_min_fails(): void
    {
        $validator = $this->validate(['per_page' => 9]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_per_page_above_max_fails(): void
    {
        $validator = $this->validate(['per_page' => 101]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_per_page_non_integer_fails(): void
    {
        $validator = $this->validate(['per_page' => 'twenty']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    // ========================================
    // page 필드 테스트
    // ========================================

    public function test_page_at_min_passes(): void
    {
        $validator = $this->validate(['page' => 1]);

        $this->assertFalse($validator->fails());
    }

    public function test_page_large_number_passes(): void
    {
        $validator = $this->validate(['page' => 9999]);

        $this->assertFalse($validator->fails());
    }

    public function test_page_zero_fails(): void
    {
        $validator = $this->validate(['page' => 0]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
    }

    public function test_page_negative_fails(): void
    {
        $validator = $this->validate(['page' => -1]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
    }

    public function test_page_non_integer_fails(): void
    {
        $validator = $this->validate(['page' => 'first']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
    }

    // ========================================
    // 복합 요청 테스트
    // ========================================

    public function test_complete_filter_request_passes(): void
    {
        $validator = $this->validate([
            'search' => '국내',
            'shipping_methods' => ['parcel', 'quick'],
            'charge_policies' => ['fixed', 'conditional_free'],
            'countries' => ['KR'],
            'is_active' => 'true',
            'sort_by' => 'name',
            'sort_order' => 'asc',
            'per_page' => 50,
            'page' => 2,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_sorting_only_request_passes(): void
    {
        $validator = $this->validate([
            'sort_by' => 'created_at',
            'sort_order' => 'desc',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_pagination_only_request_passes(): void
    {
        $validator = $this->validate([
            'per_page' => 50,
            'page' => 3,
        ]);

        $this->assertFalse($validator->fails());
    }
}
