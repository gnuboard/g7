<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 조회 요청 검증 테스트
 */
class OrderListRequestTest extends ModuleTestCase
{
    /**
     * 검증 수행
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new OrderListRequest();

        return Validator::make($data, $request->rules());
    }

    public function test_valid_request_passes(): void
    {
        $validator = $this->validate([
            'order_status' => ['pending_payment'],
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'search_keyword' => '홍길동',
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

    public function test_invalid_order_status_fails(): void
    {
        $validator = $this->validate(['order_status' => ['invalid_status']]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order_status.0', $validator->errors()->toArray());
    }

    public function test_invalid_date_format_fails(): void
    {
        // 완전히 잘못된 날짜 형식 사용 (Laravel의 date 규칙은 다양한 형식을 허용함)
        $validator = $this->validate(['start_date' => 'invalid-date']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
    }

    public function test_end_date_before_start_date_fails(): void
    {
        $validator = $this->validate([
            'start_date' => '2025-01-31',
            'end_date' => '2025-01-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_date', $validator->errors()->toArray());
    }

    public function test_per_page_below_min_fails(): void
    {
        $validator = $this->validate(['per_page' => 5]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_per_page_above_max_fails(): void
    {
        $validator = $this->validate(['per_page' => 200]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_valid_search_field_passes(): void
    {
        $validator = $this->validate([
            'search_field' => 'order_number',
            'search_keyword' => 'ORD-001',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_search_field_fails(): void
    {
        $validator = $this->validate([
            'search_field' => 'invalid_field',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('search_field', $validator->errors()->toArray());
    }

    public function test_search_keyword_max_length(): void
    {
        $validator = $this->validate([
            'search_keyword' => str_repeat('a', 201),
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('search_keyword', $validator->errors()->toArray());
    }

    public function test_valid_sort_by_passes(): void
    {
        $validator = $this->validate([
            'sort_by' => 'ordered_at',
            'sort_order' => 'desc',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_sort_by_fails(): void
    {
        $validator = $this->validate([
            'sort_by' => 'invalid_field',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sort_by', $validator->errors()->toArray());
    }

    public function test_invalid_sort_order_fails(): void
    {
        $validator = $this->validate([
            'sort_order' => 'invalid',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sort_order', $validator->errors()->toArray());
    }

    public function test_multiple_order_status_passes(): void
    {
        $validator = $this->validate([
            'order_status' => ['pending_payment', 'payment_complete', 'shipping'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_valid_payment_method_passes(): void
    {
        $validator = $this->validate([
            'payment_method' => ['card', 'vbank'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_payment_method_fails(): void
    {
        $validator = $this->validate([
            'payment_method' => ['invalid_method'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payment_method.0', $validator->errors()->toArray());
    }

    public function test_valid_amount_range_passes(): void
    {
        $validator = $this->validate([
            'min_amount' => 10000,
            'max_amount' => 100000,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_negative_min_amount_fails(): void
    {
        $validator = $this->validate([
            'min_amount' => -1000,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('min_amount', $validator->errors()->toArray());
    }

    public function test_valid_shipping_policy_id_passes(): void
    {
        $validator = $this->validate([
            'shipping_policy_id' => 1,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_shipping_policy_id_fails(): void
    {
        $validator = $this->validate([
            'shipping_policy_id' => 'not_a_number',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('shipping_policy_id', $validator->errors()->toArray());
    }

    public function test_valid_shipping_amount_range_passes(): void
    {
        $validator = $this->validate([
            'min_shipping_amount' => 0,
            'max_shipping_amount' => 5000,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_negative_min_shipping_amount_fails(): void
    {
        $validator = $this->validate([
            'min_shipping_amount' => -1000,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('min_shipping_amount', $validator->errors()->toArray());
    }

    public function test_negative_max_shipping_amount_fails(): void
    {
        $validator = $this->validate([
            'max_shipping_amount' => -500,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('max_shipping_amount', $validator->errors()->toArray());
    }

    public function test_all_new_filters_combined_passes(): void
    {
        $validator = $this->validate([
            'shipping_policy_id' => 2,
            'min_shipping_amount' => 3000,
            'max_shipping_amount' => 10000,
            'order_status' => ['payment_complete'],
            'per_page' => 20,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_valid_orderer_uuid_passes(): void
    {
        $validator = $this->validate([
            'orderer_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_orderer_uuid_fails(): void
    {
        $validator = $this->validate([
            'orderer_uuid' => 'not-a-uuid',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('orderer_uuid', $validator->errors()->toArray());
    }

    public function test_null_orderer_uuid_passes(): void
    {
        $validator = $this->validate([
            'orderer_uuid' => null,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_orderer_uuid_combined_with_other_filters_passes(): void
    {
        $validator = $this->validate([
            'orderer_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'order_status' => ['payment_complete'],
            'search_keyword' => '테스트',
            'per_page' => 20,
        ]);

        $this->assertFalse($validator->fails());
    }
}
