<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;

/**
 * 교환 신청서 Factory
 */
class OrderExchangeFactory extends Factory
{
    protected $model = OrderExchange::class;

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
            'exchange_number' => 'EX'.strtoupper($faker->unique()->bothify('##########')),
            'exchange_status' => ExchangeStatusEnum::REQUESTED,
            // 교환 사유 카탈로그에 있는 코드여야 한다 — `changed_mind` 는 환불·반품에만 있어 교환
            // 신청서 픽스처가 사유 라벨을 잃는다(`ClaimReasonSeeder` 의 exchange 목록 참조).
            'reason_code' => 'size_mismatch',
            'reason_detail' => null,
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'charge_method' => null,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'claim_shipping_fee' => 0,
            'mc_claim_shipping_fee' => null,
            'shipping_fee_snapshot' => null,
            'additional_product_amount' => 0,
            'refund_product_amount' => 0,
            'total_charge_amount' => 0,
            'mc_total_charge_amount' => null,
            'currency_code' => 'KRW',
            'items_snapshot' => [],
            'return_address_snapshot' => null,
            'redelivery_address_snapshot' => null,
            'collection_completed_at' => null,
            'redelivered_at' => null,
            'exchange_mode' => 'standard',
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
     * 특정 상태의 교환 신청서로 설정합니다.
     *
     * @param  ExchangeStatusEnum  $status  교환 상태
     * @return static
     */
    public function status(ExchangeStatusEnum $status): static
    {
        return $this->state(fn () => ['exchange_status' => $status]);
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
        return $this->state(fn () => [
            'requested_by' => null,
            'order_id' => Order::factory()->state(['user_id' => null]),
        ]);
    }
}
