<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 클레임 신청 흐름 테스트용 주문 조립 헬퍼 (S5)
 *
 * `BuildsClaimAdjustmentOrders`(계산 기반 주문 조립) 위에 배송완료 전이·배송정책 배송 행·클레임 고정
 * 배송비·사유 카탈로그·마일리지 lot·PG provider 레지스트리 같은 신청 흐름의 전제를 얹는다.
 * 교환·반품 서비스 테스트의 동형 헬퍼와 같은 값을 쓴다(배송지 KR 06236, 배송완료 하루 전).
 */
trait BuildsClaimFlowOrders
{
    use BuildsClaimAdjustmentOrders;

    /**
     * 교환·반품·환불 사유 카탈로그를 시드합니다.
     */
    protected function seedClaimReasons(): void
    {
        foreach (['exchange', 'return', 'refund'] as $type) {
            foreach ([
                ['changed_mind', ClaimReasonFaultTypeEnum::CUSTOMER],
                ['size_mismatch', ClaimReasonFaultTypeEnum::CUSTOMER],
                ['defective_product', ClaimReasonFaultTypeEnum::SELLER],
            ] as [$code, $fault]) {
                ClaimReason::firstOrCreate(
                    ['type' => $type, 'code' => $code],
                    [
                        'name' => ['ko' => $code, 'en' => $code],
                        'fault_type' => $fault,
                        'is_user_selectable' => true,
                        'is_active' => true,
                        'sort_order' => 0,
                    ]
                );
            }
        }
    }

    /**
     * 주문 상품을 지정 상태로 만들고 배송지(KR)를 붙입니다.
     *
     * @param  Order  $order  주문
     * @param  OrderStatusEnum  $status  주문옵션·주문 상태
     * @param  string  $countryCode  배송지 국가
     * @return Order 갱신된 주문
     */
    protected function moveOrderTo(Order $order, OrderStatusEnum $status, string $countryCode = 'KR'): Order
    {
        foreach ($order->options as $option) {
            $option->forceFill([
                'option_status' => $status,
                'delivered_at' => $status === OrderStatusEnum::DELIVERED || $status === OrderStatusEnum::CONFIRMED ? now()->subDay() : null,
                'is_stock_deducted' => true,
            ])->save();
        }

        $order->forceFill(['order_status' => $status])->save();

        if (! $order->addresses()->where('address_type', 'shipping')->exists()) {
            OrderAddress::factory()->create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'orderer_name' => '홍길동',
                'orderer_phone' => '010-1234-5678',
                'orderer_email' => 'claim@example.com',
                'recipient_country_code' => $countryCode,
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
                'address_detail' => '101호',
            ]);
        }

        return $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);
    }

    /**
     * 주문 옵션마다 배송정책이 붙은 배송 행을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  ShippingPolicy  $policy  배송정책
     * @return Order 갱신된 주문
     */
    protected function attachPolicyShippings(Order $order, ShippingPolicy $policy): Order
    {
        $order->shippings()->delete();

        foreach ($order->options as $option) {
            OrderShipping::factory()->create([
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'shipping_policy_id' => $policy->id,
                'delivery_policy_snapshot' => ['country_code' => 'KR'],
                'base_shipping_amount' => 0,
                'extra_shipping_amount' => 0,
                'total_shipping_amount' => 0,
                'shipping_discount_amount' => 0,
            ]);
        }

        return $order->fresh(['options', 'shippings', 'payment']);
    }

    /**
     * 배송완료된 단일 상품 주문을 만듭니다. (클레임 고정 배송비 설정 포함)
     *
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  int  $returnFee  반품 고정 배송비 (편도)
     * @param  int  $exchangeFee  교환 고정 배송비 (왕복)
     * @param  array  $orderOverrides  Order 오버라이드
     * @param  array  $paymentOverrides  결제행 오버라이드
     * @return Order 배송완료 주문
     */
    protected function deliveredClaimOrder(
        int $price = 29000,
        int $quantity = 1,
        int $returnFee = 3000,
        int $exchangeFee = 6000,
        array $orderOverrides = [],
        array $paymentOverrides = [],
    ): Order {
        $policy = $this->createClaimShippingPolicy();
        $policy->countrySettings()->where('country_code', 'KR')->update([
            'claim_fee_mode' => 'fixed',
            'return_fixed_fee' => $returnFee,
            'exchange_fixed_fee' => $exchangeFee,
        ]);

        $order = $this->createSingleItemClaimOrder($price, $quantity, $policy, $orderOverrides + ['currency_snapshot' => $this->krwCurrencySnapshot()], $paymentOverrides);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::DELIVERED);
    }

    /**
     * 결제완료(즉시취소 가능) 상태의 단일 상품 주문을 만듭니다.
     *
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @return Order 결제완료 주문
     */
    protected function paidClaimOrder(int $price = 29000, int $quantity = 1): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder($price, $quantity, $policy, ['currency_snapshot' => $this->krwCurrencySnapshot()]);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PAYMENT_COMPLETE);
    }

    /**
     * 회원에게 마일리지 lot 을 지급합니다. (원장 직접 삽입 — 결제 테스트의 잔액 전제)
     *
     * @param  int  $userId  회원 ID
     * @param  int  $amount  지급액
     * @param  string  $currency  통화
     * @return MileageTransaction 적립 lot
     */
    protected function grantMileage(int $userId, int $amount, string $currency = 'KRW'): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'expires_at' => now()->addYear(),
        ]);
    }

    /**
     * 테스트 PG provider 를 레지스트리에 등록하고 기본 PG 로 지정합니다.
     *
     * @param  string  $id  provider id
     * @param  string|null  $handler  프론트 결제 진입 핸들러
     */
    protected function registerTestPgProvider(string $id = 'testpg', ?string $handler = 'sirsoft-testpg.requestPayment'): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', function (array $providers) use ($id, $handler) {
            $providers[] = ['id' => $id, 'name' => 'Test PG', 'payment_handler' => $handler];

            return $providers;
        });

        app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', $id);
    }

    /**
     * 클레임 결제 승인 훅을 성공/실패로 고정합니다.
     *
     * @param  bool  $success  승인 성공 여부
     * @param  array  $overrides  반환값 덮어쓰기
     * @param  array|null  $captured  마지막 호출 인자 (참조)
     */
    protected function fakeClaimPaymentApprove(bool $success = true, array $overrides = [], ?array &$captured = null): void
    {
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, $payment, array $callback) use ($success, $overrides, &$captured) {
            $captured = ['payment' => $payment, 'callback' => $callback];

            return array_merge($default, [
                'handled' => true,
                'success' => $success,
                'transaction_id' => $success ? 'TXN-'.$payment->payment_number : null,
                'error_code' => $success ? null : 'DECLINED',
                'error_message' => $success ? null : 'card declined',
            ], $overrides);
        });
    }

    /**
     * 클레임 결제 환급 훅을 성공/실패로 고정합니다.
     *
     * @param  bool  $success  환급 성공 여부
     * @param  array|null  $calls  호출 기록 (참조)
     */
    protected function fakeClaimPaymentRefund(bool $success = true, ?array &$calls = null): void
    {
        $calls = [];

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.refund', function (array $default, $payment, float $amount, ?string $reason, string $key) use ($success, &$calls) {
            $calls[] = ['payment_number' => $payment->payment_number, 'amount' => $amount, 'reason' => $reason, 'key' => $key];

            return array_merge($default, [
                'handled' => true,
                'success' => $success,
                'transaction_id' => $success ? 'RTXN-'.count($calls) : null,
                'error_code' => $success ? null : 'REFUND_DECLINED',
                'error_message' => $success ? null : 'refund declined',
            ]);
        });
    }

    /**
     * KRW 단일 통화 주문의 통화 스냅샷을 만듭니다. (소수 0자리 — PG 최소 화폐단위 ×1)
     *
     * @return array 통화 스냅샷
     */
    protected function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
            ],
        ];
    }
}
