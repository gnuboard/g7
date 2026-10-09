<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

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
    use ProvidesClaimTracking;

    /**
     * 교환·반품·환불 사유 카탈로그를 시드합니다.
     *
     * @return void 반환값 없음
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
     * @param  array  $paymentOverrides  OrderPayment 오버라이드 (결제수단 게이트 축 — P16)
     * @return Order 결제완료 주문
     */
    protected function paidClaimOrder(int $price = 29000, int $quantity = 1, array $paymentOverrides = []): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(
            $price,
            $quantity,
            $policy,
            ['currency_snapshot' => $this->krwCurrencySnapshot()],
            $paymentOverrides,
        );

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
     * 기본 PG 설정은 **테스트가 끝나면 원래 값으로 되돌린다.** 훅 등록은 `ModuleTestCase` 가
     * 스냅샷으로 복원하지만 설정 쓰기는 그 밖이라, 되돌리지 않으면 값이 그대로 남는다 —
     * 개발 DB 를 보는 환경에서 `artisan test` 를 돌리면 **상점의 기본 PG 가 `testpg` 로 바뀐다.**
     * 그러면 이후 만들어지는 결제행이 그 값으로 고정되고(`buildPaymentPayload()` 가 행에 박힌
     * 값을 우선한다), 고객 화면은 **200 을 받고도 결제창을 못 연다** — 등록된 provider 가
     * 아니라 `pg_payment_handler` 가 `null` 이 되기 때문이다. 오류가 없어 원인이 드러나지
     * 않는다(2026-09-15 실측 — `CP20260915-1004285647` 이 그렇게 고정됐다).
     *
     * @param  string  $id  PG 제공자 ID
     * @param  string|null  $handler  프론트 결제 진입 핸들러
     * @return void 반환값 없음
     */
    protected function registerTestPgProvider(string $id = 'testpg', ?string $handler = 'sirsoft-testpg.requestPayment'): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', function (array $providers) use ($id, $handler) {
            $providers[] = ['id' => $id, 'name' => 'Test PG', 'payment_handler' => $handler];

            return $providers;
        });

        $settings = app(EcommerceSettingsService::class);
        $previous = $settings->getSetting('order_settings.default_pg_provider');

        $settings->setSetting('order_settings.default_pg_provider', $id);

        $this->beforeApplicationDestroyed(static function () use ($previous): void {
            app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', $previous);
        });
    }

    /**
     * 클레임 결제 승인 훅을 성공/실패로 고정합니다.
     *
     * @param  bool  $success  승인 성공 여부
     * @param  array  $overrides  반환값 덮어쓰기
     * @param  array|null  $captured  마지막 호출 인자 (참조)
     * @return void 반환값 없음
     */
    protected function fakeClaimPaymentApprove(bool $success = true, array $overrides = [], ?array &$captured = null): void
    {
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, $payment, array $callback) use ($success, $overrides, &$captured) {
            $captured = ['payment' => $payment, 'callback' => $callback];

            return array_merge($default, [
                'handled' => true,
                'success' => $success,
                'transaction_id' => $success ? 'TXN-'.$payment->payment_number : null,
                // 실제 PG 처럼 승인 금액을 돌려준다 — 서버는 금액을 대조할 수 없는 승인을 확정하지
                // 않는다(#599 validate P2 #11). 불일치를 재는 테스트는 `$overrides` 로 덮는다.
                'paid_amount' => $success
                    ? app(ClaimPaymentService::class)->resolvePaymentCharge($payment)['minor_unit_amount'] ?? null
                    : null,
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
     * @return void 반환값 없음
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
     * 마일리지로 전액 결제한(현금 결제액 0원) 회원 주문을 만듭니다. (결함 4 — 현금 0원 환불)
     *
     * 고객은 마일리지 결제수단(`point`)을 고를 수 없어, 전액 마일리지 주문도 고른 수단(카드·무통장·
     * 가상계좌·무료)을 그대로 단 채 결제완료로 끝난다. 계산기가 사용 마일리지를 결제금액에 맞춰 자르므로
     * (`usePoints` 를 넉넉히 준다) 결제금액은 0 이고, 실제 차감 원장도 함께 남긴다(`is_mileage_deducted`).
     *
     * @param  User  $member  주문 회원
     * @param  string  $paymentMethod  주문서에서 고른 결제수단 코드
     * @param  string  $pgProvider  PG 식별자 (PG 를 타지 않는 수단은 빈 문자열)
     * @param  OrderStatusEnum  $status  맞출 주문 상태
     * @return Order 현금 0원 주문 (options·payment·shippings 로드됨)
     */
    protected function mileageOnlyPaidOrder(
        User $member,
        string $paymentMethod,
        string $pgProvider = '',
        OrderStatusEnum $status = OrderStatusEnum::PAYMENT_COMPLETE,
    ): Order {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);

        $policy = $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct(29000, $policy);

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
                usePoints: 999999,
            ),
            [
                'user_id' => $member->id,
                'is_mileage_deducted' => true,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ],
            [
                'payment_method' => $paymentMethod,
                'pg_provider' => $pgProvider,
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_amount_local' => 0,
                'paid_amount_base' => 0,
            ],
        );
        $order = $this->attachPolicyShippings($order, $policy);

        $points = (int) $order->total_points_used_amount;
        $this->grantMileage($member->id, $points);
        app(UserMileageService::class)->deductFifo($member->id, $points, 'KRW', $order);

        return $status === OrderStatusEnum::PAYMENT_COMPLETE
            ? $order->fresh(['options', 'payment', 'shippings'])
            : $this->moveOrderTo($order, $status);
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

    /**
     * 결제 통화가 기본 통화와 다른 배송완료 주문을 만듭니다. (다통화 잔여 한도 축)
     *
     * D19 의 수단별 잔여는 결제 통화 원값(`remaining_local`)과 기본 통화 환산값
     * (`remaining_base`)을 함께 싣는다. 두 값이 같은 KRW 단일 통화 주문만으로는
     * 화면이 어느 쪽을 읽든 통과하므로 단위 오류가 드러나지 않는다.
     *
     * @param  float  $rate  기본 통화 1단위당 결제 통화 환율 (기본 0.001 = 1 USD ↔ 1,000 KRW)
     * @param  float  $paidLocal  결제 통화 실청구액
     * @return Order 배송완료 주문 (결제행 통화 USD)
     */
    protected function foreignPaidClaimOrder(float $rate = 0.001, float $paidLocal = 50): Order
    {
        return $this->deliveredClaimOrder(
            orderOverrides: ['currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'USD',
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                    'USD' => ['rate' => $rate, 'decimal_places' => 2, 'rounding_unit' => '0.01', 'rounding_method' => 'floor'],
                ],
            ]],
            paymentOverrides: [
                'currency' => 'USD',
                'paid_amount_local' => $paidLocal,
                'paid_amount_base' => $paidLocal / $rate,
            ],
        );
    }

    /**
     * 저가 옵션으로 교환해 재발송완료까지 진행한 교환을 만듭니다. (완료 직전 — 환불 차액 有)
     *
     * D19 ① 축의 교환 분기는 재계산 산출물이 아니라 신청 시점 동결값(`refund_product_amount`)을
     * 정본으로 삼는다. 그 값이 살아 있는 픽스처가 있어야 교환 발 환불신청서의 산정·실행을 잴 수 있다.
     *
     * 왕복 배송비는 '환불금 차감'이다 — 차액에서 배송비를 빼는 접수 산식(`deducted_shipping_fee`)이
     * 교환 발 환불신청서의 정상 형태이고, 차액이 배송비보다 커야 접수가 성립한다.
     *
     * @param  int  $priceAdjustment  교환 대상 옵션의 가격 조정 (음수 = 저가)
     * @return array{0: Order, 1: OrderExchange} 주문 + 교환 (REDELIVERED)
     */
    protected function redeliveredLowerPricedExchange(int $priceAdjustment = -10000): array
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderExchangeService::class);

        $newOption = ProductOptionFactory::new()
            ->forProduct($order->options->first()->product)
            ->create([
                'price_adjustment' => $priceAdjustment,
                'stock_quantity' => 100,
                'is_default' => false,
                'is_active' => true,
            ]);

        $exchange = $service->requestExchange($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
                'new_product_option_id' => $newOption->id,
            ]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        foreach ([
            ExchangeStatusEnum::APPROVED,
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING,
            ExchangeStatusEnum::REDELIVERED,
        ] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        return [$order->fresh(['options', 'shippings', 'payments']), $exchange->fresh(['exchangeOptions'])];
    }
}
