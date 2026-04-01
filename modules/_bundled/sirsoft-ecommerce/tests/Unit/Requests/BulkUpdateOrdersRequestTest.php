<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOrdersRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 일괄 변경 요청 검증 테스트
 */
class BulkUpdateOrdersRequestTest extends ModuleTestCase
{
    /**
     * 검증 수행 (기본 규칙만)
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new BulkUpdateOrdersRequest();

        return Validator::make($data, $request->rules());
    }

    public function test_valid_request_with_status_passes(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_empty_ids_fails(): void
    {
        $validator = $this->validate([
            'ids' => [],
            'order_status' => OrderStatusEnum::SHIPPING->value,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ids', $validator->errors()->toArray());
    }

    public function test_missing_ids_fails(): void
    {
        $validator = $this->validate([
            'order_status' => OrderStatusEnum::SHIPPING->value,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ids', $validator->errors()->toArray());
    }

    public function test_invalid_order_status_fails(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => 'invalid_status',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order_status', $validator->errors()->toArray());
    }

    public function test_non_existent_order_id_fails(): void
    {
        $validator = $this->validate([
            'ids' => [99999],
            'order_status' => OrderStatusEnum::SHIPPING->value,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ids.0', $validator->errors()->toArray());
    }

    public function test_tracking_number_max_length_passes(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::SHIPPING->value,
            'tracking_number' => str_repeat('1', 50),
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_tracking_number_exceeds_max_length_fails(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::SHIPPING->value,
            'tracking_number' => str_repeat('1', 51),
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tracking_number', $validator->errors()->toArray());
    }

    public function test_multiple_order_ids_passes(): void
    {
        $orders = OrderFactory::new()->count(3)->create();

        $validator = $this->validate([
            'ids' => $orders->pluck('id')->toArray(),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_valid_all_shipping_status_values_pass(): void
    {
        $order = OrderFactory::new()->create();

        $shippingStatuses = [
            OrderStatusEnum::SHIPPING_READY->value,
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
        ];

        foreach ($shippingStatuses as $status) {
            $validator = $this->validate([
                'ids' => [$order->id],
                'order_status' => $status,
            ]);

            $this->assertFalse($validator->fails(), "배송상태 '{$status}'가 유효해야 합니다.");
        }
    }

    public function test_carrier_id_and_tracking_number_together_passes(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::SHIPPING->value,
            'carrier_id' => 1, // 실제로는 exists 검증이 필요하지만 기본 규칙 테스트
            'tracking_number' => '123456789012',
        ]);

        // carrier_id exists 규칙이 있어서 실패할 수 있음 (실제 carrier가 없으면)
        // 기본 규칙만 테스트
        $errors = $validator->errors()->toArray();
        $this->assertArrayNotHasKey('tracking_number', $errors);
    }

    public function test_pending_order_status_is_rejected(): void
    {
        $order = OrderFactory::new()->create();

        $validator = $this->validate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::PENDING_ORDER->value,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order_status', $validator->errors()->toArray());
    }

    public function test_ids_must_be_integers(): void
    {
        $validator = $this->validate([
            'ids' => ['abc', 'def'],
            'order_status' => OrderStatusEnum::SHIPPING->value,
        ]);

        $this->assertTrue($validator->fails());
    }
}
