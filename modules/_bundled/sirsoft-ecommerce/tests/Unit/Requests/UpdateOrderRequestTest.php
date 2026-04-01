<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateOrderRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 수정 요청 검증 테스트
 */
class UpdateOrderRequestTest extends ModuleTestCase
{
    /**
     * 필수 필드가 포함된 기본 유효 데이터
     *
     * @return array
     */
    protected function validData(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'recipient_zipcode' => '12345',
            'recipient_address' => '서울특별시 강남구 테헤란로 123',
            'recipient_detail_address' => '101동 202호',
        ], $overrides);
    }

    /**
     * 검증 수행
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new UpdateOrderRequest();

        return Validator::make($data, $request->rules());
    }

    public function test_valid_request_passes(): void
    {
        $validator = $this->validate($this->validData([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
            'admin_memo' => '테스트 메모입니다.',
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_empty_request_fails(): void
    {
        // recipient_name, recipient_zipcode가 required이므로 빈 요청은 실패해야 함
        $validator = $this->validate([]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_name', $validator->errors()->toArray());
        $this->assertArrayHasKey('recipient_zipcode', $validator->errors()->toArray());
        $this->assertArrayHasKey('recipient_address', $validator->errors()->toArray());
        $this->assertArrayHasKey('recipient_detail_address', $validator->errors()->toArray());
    }

    public function test_valid_order_status_passes(): void
    {
        foreach (OrderStatusEnum::values() as $status) {
            $validator = $this->validate($this->validData(['order_status' => $status]));
            $this->assertFalse($validator->fails(), "주문상태 '{$status}'가 유효해야 합니다.");
        }
    }

    public function test_invalid_order_status_fails(): void
    {
        $validator = $this->validate($this->validData(['order_status' => 'invalid_status']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order_status', $validator->errors()->toArray());
    }

    public function test_admin_memo_max_length_passes(): void
    {
        $validator = $this->validate($this->validData([
            'admin_memo' => str_repeat('a', 2000),
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_admin_memo_exceeds_max_length_fails(): void
    {
        $validator = $this->validate($this->validData([
            'admin_memo' => str_repeat('a', 2001),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('admin_memo', $validator->errors()->toArray());
    }

    public function test_admin_memo_null_passes(): void
    {
        $validator = $this->validate($this->validData([
            'admin_memo' => null,
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_order_status_only_with_required_fields_passes(): void
    {
        $validator = $this->validate($this->validData([
            'order_status' => OrderStatusEnum::SHIPPING->value,
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_admin_memo_only_with_required_fields_passes(): void
    {
        $validator = $this->validate($this->validData([
            'admin_memo' => '관리자 메모만 업데이트',
        ]));

        $this->assertFalse($validator->fails());
    }

    // --- 수취인 필수 필드 검증 ---

    public function test_recipient_name_required(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_name' => '',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_name', $validator->errors()->toArray());
    }

    public function test_recipient_name_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_name' => str_repeat('가', 51),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_name', $validator->errors()->toArray());
    }

    public function test_recipient_zipcode_required(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_zipcode' => '',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_zipcode', $validator->errors()->toArray());
    }

    public function test_recipient_address_required(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_address' => '',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_address', $validator->errors()->toArray());
    }

    public function test_recipient_address_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_address' => str_repeat('가', 256),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_address', $validator->errors()->toArray());
    }

    public function test_recipient_detail_address_required(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_detail_address' => '',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_detail_address', $validator->errors()->toArray());
    }

    public function test_recipient_phone_or_tel_required(): void
    {
        // 둘 다 없으면 실패
        $validator = $this->validate($this->validData([
            'recipient_phone' => null,
            'recipient_tel' => null,
        ]));

        $this->assertTrue($validator->fails());
    }

    public function test_recipient_phone_only_passes(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_phone' => '010-1234-5678',
            'recipient_tel' => null,
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_recipient_tel_only_passes(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_phone' => null,
            'recipient_tel' => '02-1234-5678',
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_recipient_both_phone_and_tel_passes(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_phone' => '010-1234-5678',
            'recipient_tel' => '02-1234-5678',
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_delivery_memo_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'delivery_memo' => str_repeat('a', 501),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('delivery_memo', $validator->errors()->toArray());
    }

    public function test_recipient_detail_address_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'recipient_detail_address' => str_repeat('가', 256),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('recipient_detail_address', $validator->errors()->toArray());
    }

    public function test_all_optional_fields_null_passes(): void
    {
        $validator = $this->validate($this->validData([
            'order_status' => null,
            'admin_memo' => null,
            'recipient_tel' => null,
            'delivery_memo' => null,
        ]));

        $this->assertFalse($validator->fails());
    }
}
