<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 반품 신청서 Factory
 */
class OrderReturnFactory extends Factory
{
    protected $model = OrderReturn::class;

    /**
     * 기본 정의
     *
     * @return array 기본 속성 배열
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            'order_id' => Order::factory(),
            'return_number' => 'RT'.strtoupper($faker->unique()->bothify('##########')),
            'return_status' => ReturnStatusEnum::REQUESTED,
            'reason_code' => 'changed_mind',
            'reason_detail' => null,
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'charge_method' => null,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'claim_shipping_fee' => 0,
            'mc_claim_shipping_fee' => null,
            'shipping_fee_snapshot' => null,
            'total_charge_amount' => 0,
            'mc_total_charge_amount' => null,
            'currency_code' => 'KRW',
            'items_snapshot' => [],
            'return_address_snapshot' => null,
            'collection_completed_at' => null,
            'is_stock_restored' => false,
            'requested_by' => null,
            'processed_by' => null,
            'approved_at' => null,
            'on_hold_at' => null,
            'rejected_at' => null,
            'withdrawn_at' => null,
            'completed_at' => null,
            'reject_reason' => null,
            'admin_memo' => null,
        ];
    }

    /**
     * 특정 상태의 반품 신청서로 설정합니다.
     *
     * @param  ReturnStatusEnum  $status  반품 상태
     * @return static
     */
    public function status(ReturnStatusEnum $status): static
    {
        return $this->state(fn () => ['return_status' => $status]);
    }

    /**
     * 판매자 귀책(배송비 0원) 신청서로 설정합니다.
     *
     * @return static
     */
    public function sellerFault(): static
    {
        return $this->state(fn () => [
            'fault_type' => ClaimReasonFaultTypeEnum::SELLER,
            'claim_shipping_fee' => 0,
            'total_charge_amount' => 0,
        ]);
    }

    /**
     * 비회원 신청 건으로 설정합니다.
     *
     * @return static
     */
    public function guest(): static
    {
        // 비회원은 **주문에 주인이 없는** 것이다. `requested_by` 만 비우면 운영자 대리 접수와
        // 구분되지 않아(§4-3-1), 그 픽스처로 잠근 판정이 회원 주문까지 비회원으로 만든다.
        // (`OrderExchangeFactory::guest()` 와 같은 규칙)
        return $this->state(fn () => [
            'requested_by' => null,
            'order_id' => Order::factory()->state(['user_id' => null]),
        ]);
    }
}
