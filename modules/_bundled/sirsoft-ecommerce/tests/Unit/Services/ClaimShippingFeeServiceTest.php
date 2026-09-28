<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ClaimShippingFeeService;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 클레임 배송비 산정 서비스 테스트 (S2b1 본체 + S2b2 확장)
 *
 * 검증 축:
 * - 청구 대상 유형 게이트 (환불·취소는 회수 배송이 없어 청구 없음)
 * - 귀책 3종 (판매자·배송사 즉시 0원 / 고객 산정)
 * - `fixed` 모드 (반품 `return_fixed_fee` 편도 · 교환 `exchange_fixed_fee` 왕복 총액, 배수 미적용)
 * - `original_policy` 모드 × 부과정책 6종 (무료·고정·조건부무료 미달/충족·수량당·구간) × 반품 ×1 / 교환 ×2
 * - 다중 배송정책 그룹 합산과 헤더 모드 결정
 * - 대상 아이템 정규화와 정책 미해석 시 0원 처리
 * - 부과정책 파라미터의 출처 (주문 시점 스냅샷 우선 · 스냅샷 없는 과거 주문만 현재 정책 행)
 * - 도서산간 추가배송비 (회수 구간 · 교환 재발송 구간 · `extra_fee_multiply`)
 * - 재발송지 변경 시 도서산간 재판정 (D14)
 * - 그룹 청구액의 수량 안분과 근거 아이템 기록
 * - 주문 시점 환율 기준 다통화 금액 (`mc_claim_shipping_fee`)
 * - 외부 요금 API 정책의 기본 배송비 폴백 (클레임 문맥에서는 외부 호출 없음)
 */
class ClaimShippingFeeServiceTest extends ModuleTestCase
{
    protected ClaimShippingFeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->service = app(ClaimShippingFeeService::class);
    }

    protected function tearDown(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');
        if (file_exists($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 테스트용 통화 설정을 기록합니다.
     */
    protected function setupTestCurrencySettings(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode([
                'default_language' => 'ko',
                'default_currency' => 'KRW',
                'currencies' => [[
                    'code' => 'KRW',
                    'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                    'exchange_rate' => null,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'is_default' => true,
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 국가별 설정 1행이 딸린 배송정책을 만듭니다.
     *
     * @param  array  $country  국가별 설정 override
     * @param  string  $countryCode  국가코드
     * @return ShippingPolicy 생성된 배송정책
     */
    protected function makePolicy(array $country = [], string $countryCode = 'KR'): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create(array_merge([
            'country_code' => $countryCode,
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => 3000,
            'free_threshold' => null,
            'ranges' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'return_fixed_fee' => 3000,
            'exchange_fixed_fee' => 5000,
            'is_active' => true,
        ], $country));

        return $policy->load('countrySettings');
    }

    /**
     * 빈 주문을 만듭니다.
     *
     * @param  array  $overrides  주문 override
     * @return Order 생성된 주문
     */
    protected function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_status' => OrderStatusEnum::DELIVERED,
        ], $overrides));
    }

    /**
     * 주문 시점 배송지(국가/우편번호) 스냅샷을 기록합니다.
     *
     * 도서산간 판정의 SSoT 이며, 이 스냅샷이 없으면 서비스는 주문 배송주소로 폴백한다.
     *
     * @param  Order  $order  대상 주문
     * @param  string|null  $zipcode  우편번호
     * @param  string  $countryCode  국가코드
     * @return Order 갱신된 주문
     */
    protected function withDeliveryAddress(Order $order, ?string $zipcode, string $countryCode = 'KR'): Order
    {
        $order->forceFill([
            'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make([], [
                'country_code' => $countryCode,
                'zipcode' => $zipcode,
            ]),
        ])->save();

        return $order;
    }

    /**
     * 주문 시점 배송정책 스냅샷(`delivery_policy_snapshot`) 페이로드를 만듭니다.
     *
     * 주문 생성 시 `OrderCalculationService::calculateShippingFee()` 가 기록하는 것과 같은
     * 키 집합이다 — 클레임 약관 3종(`claim_fee_mode`·고정액 2종)은 여기에 없다.
     *
     * @param  array  $overrides  스냅샷 override
     * @param  string  $countryCode  국가코드
     * @return array 배송정책 스냅샷
     */
    protected function snapshotPolicy(array $overrides = [], string $countryCode = 'KR'): array
    {
        return array_merge([
            'country_code' => $countryCode,
            'shipping_method' => 'parcel',
            'custom_shipping_name' => null,
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'free_threshold' => null,
            'ranges' => null,
            'extra_fee_enabled' => false,
            'extra_fee_multiply' => false,
            'extra_fee_settings' => [],
        ], $overrides);
    }

    /**
     * 도서산간 추가배송비 설정 override 를 만듭니다.
     *
     * @param  int  $fee  추가배송비
     * @param  string  $pattern  우편번호 패턴 (범위·와일드카드·정확일치)
     * @param  bool  $multiply  per_* 정책에서 단위 수만큼 중복 부과할지 여부
     * @return array 국가별 설정 override
     */
    protected function remoteArea(int $fee = 3000, string $pattern = '63000-63999', bool $multiply = false): array
    {
        return [
            'extra_fee_enabled' => true,
            'extra_fee_settings' => [['zipcode' => $pattern, 'fee' => $fee]],
            'extra_fee_multiply' => $multiply,
        ];
    }

    /**
     * 두 통화(KRW 기준 · USD 환산)를 담은 주문 시점 통화 스냅샷을 만듭니다.
     *
     * 환산 분모(base_unit) 1,000 · USD 환율 0.75 이므로 3,000원 = 2.25 USD 다.
     *
     * @return array 통화 스냅샷
     */
    protected function currencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-21T00:00:00+09:00',
        ];
    }

    /**
     * 주문 옵션과 그에 딸린 배송 행을 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  ShippingPolicy|null  $policy  배송정책 (null 이면 정책 미지정 배송 행)
     * @param  array  $overrides  주문 옵션 override
     * @param  string  $countryCode  배송 스냅샷 국가코드
     * @param  bool  $withShipping  배송 행 생성 여부
     * @return OrderOption 생성된 주문 옵션
     */
    protected function makeOption(
        Order $order,
        ?ShippingPolicy $policy,
        array $overrides = [],
        string $countryCode = 'KR',
        bool $withShipping = true,
        ?array $policySnapshot = null,
    ): OrderOption {
        $quantity = $overrides['quantity'] ?? 2;
        $subtotal = $overrides['subtotal_price'] ?? 20000;

        $option = OrderOption::factory()->create(array_merge([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::DELIVERED,
            'quantity' => $quantity,
            'unit_price' => (int) ($subtotal / max(1, $quantity)),
            'subtotal_price' => $subtotal,
            'subtotal_discount_amount' => 0,
            'product_coupon_discount_amount' => 0,
            'order_coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'unit_weight' => 0,
            'unit_volume' => 0,
        ], $overrides));

        if ($withShipping) {
            OrderShipping::factory()->create([
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'shipping_policy_id' => $policy?->id,
                'delivery_policy_snapshot' => $policySnapshot ?? ['country_code' => $countryCode],
            ]);
        }

        return $option;
    }

    /**
     * 정책 1개 + 옵션 1개짜리 최소 시나리오를 만듭니다.
     *
     * @param  array  $country  국가별 설정 override
     * @param  array  $optionOverrides  주문 옵션 override
     * @return array{0: Order, 1: OrderOption} 주문과 옵션
     */
    protected function scenario(array $country = [], array $optionOverrides = []): array
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($country);
        $option = $this->makeOption($order, $policy, $optionOverrides);

        return [$order, $option];
    }

    /**
     * 클레임 대상 아이템 배열을 만듭니다.
     *
     * @param  OrderOption  $option  주문 옵션
     * @param  int  $quantity  신청 수량
     * @return array 대상 아이템 배열
     */
    protected function items(OrderOption $option, int $quantity = 1): array
    {
        return [['order_option_id' => $option->id, 'cancel_quantity' => $quantity]];
    }

    /**
     * 산정을 실행합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array  $items  대상 아이템
     * @param  ClaimReasonFaultTypeEnum  $fault  귀책
     * @param  array|null  $redeliveryAddress  교환 재발송지 (D14)
     * @return ClaimShippingFee 산정 결과
     */
    protected function calc(
        Order $order,
        ClaimTypeEnum $type,
        array $items,
        ClaimReasonFaultTypeEnum $fault = ClaimReasonFaultTypeEnum::CUSTOMER,
        ?array $redeliveryAddress = null,
    ): ClaimShippingFee {
        return $this->service->calculate($order->fresh(), $type, $items, $fault, $redeliveryAddress);
    }

    /**
     * 원 정책 기준 모드의 국가별 설정 override 를 만듭니다.
     *
     * @param  ChargePolicyEnum  $chargePolicy  부과정책
     * @param  array  $extra  추가 override
     * @return array 국가별 설정 override
     */
    protected function originalPolicy(ChargePolicyEnum $chargePolicy, array $extra = []): array
    {
        return array_merge([
            'claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY,
            'charge_policy' => $chargePolicy,
        ], $extra);
    }

    /**
     * DoD 가 정한 부과정책 6종의 키를 반환합니다.
     *
     * 무료 · 고정 · 조건부무료(미달) · 조건부무료(충족) · 수량당 · 구간.
     *
     * @return array<int, string> 정책 키 목록
     */
    protected static function chargePolicyKeys(): array
    {
        return ['free', 'fixed', 'conditional_free_unmet', 'conditional_free_met', 'per_quantity', 'range'];
    }

    /**
     * 부과정책 키에 대응하는 국가별 설정·주문 옵션·편도 산출액을 반환합니다.
     *
     * 모든 픽스처는 수량 2 · 할인 후 40,000 원인 옵션 하나를 전량(2개) 신청하는 것을 전제한다.
     *
     * @param  string  $key  정책 키
     * @return array{country: array, option: array, one_way: int} 픽스처
     */
    protected static function chargePolicyFixture(string $key): array
    {
        $option = ['quantity' => 2, 'subtotal_price' => 40000];

        return match ($key) {
            'free' => [
                'country' => ['charge_policy' => ChargePolicyEnum::FREE, 'base_fee' => 0],
                'option' => $option,
                'one_way' => 0,
            ],
            'fixed' => [
                'country' => ['charge_policy' => ChargePolicyEnum::FIXED, 'base_fee' => 3000],
                'option' => $option,
                'one_way' => 3000,
            ],
            // 임계 500,000 → 40,000 은 미달이라 유료
            'conditional_free_unmet' => [
                'country' => [
                    'charge_policy' => ChargePolicyEnum::CONDITIONAL_FREE,
                    'base_fee' => 2500,
                    'free_threshold' => 500000,
                ],
                'option' => $option,
                'one_way' => 2500,
            ],
            // 임계 10,000 → 40,000 은 충족이라 무료
            'conditional_free_met' => [
                'country' => [
                    'charge_policy' => ChargePolicyEnum::CONDITIONAL_FREE,
                    'base_fee' => 2500,
                    'free_threshold' => 10000,
                ],
                'option' => $option,
                'one_way' => 0,
            ],
            // 수량 2 × 단위당 1,000
            'per_quantity' => [
                'country' => [
                    'charge_policy' => ChargePolicyEnum::PER_QUANTITY,
                    'base_fee' => 1000,
                    'ranges' => ['unit_value' => 1],
                ],
                'option' => $option,
                'one_way' => 2000,
            ],
            // 40,000 ≤ 50,000 구간
            'range' => [
                'country' => [
                    'charge_policy' => ChargePolicyEnum::RANGE_AMOUNT,
                    'base_fee' => 0,
                    'ranges' => ['tiers' => [['max' => 50000, 'fee' => 4000], ['max' => null, 'fee' => 0]]],
                ],
                'option' => $option,
                'one_way' => 4000,
            ],
        };
    }

    // ================================================================
    // 1. 청구 대상 유형 게이트 — 환불·취소는 회수 배송이 없다
    // ================================================================

    /**
     * @effects claim_shipping_fee_not_charged_for_refund_and_cancel
     */
    public function test_refund_claim_type_is_not_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE, $result->reason);
    }

    /**
     * @effects claim_shipping_fee_not_charged_for_refund_and_cancel
     */
    public function test_cancel_claim_type_is_not_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::CANCEL, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE, $result->reason);
    }

    public function test_refund_claim_type_is_not_charged_even_for_seller_fault(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE, $result->reason);
    }

    public function test_cancel_claim_type_is_not_charged_for_carrier_fault(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::CANCEL, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
    }

    public function test_non_chargeable_claim_type_records_fixed_mode_by_default(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option));

        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    public function test_non_chargeable_claim_type_has_no_group_basis(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option));

        $this->assertSame([], $result->groups);
    }

    // ================================================================
    // 2. 귀책 3종
    // ================================================================

    public function test_return_with_customer_fault_is_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
        $this->assertTrue($result->charged);
    }

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    public function test_return_with_seller_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);
    }

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    public function test_return_with_carrier_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);
    }

    public function test_exchange_with_customer_fault_is_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(5000, $result->fee);
    }

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    public function test_exchange_with_seller_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    public function test_exchange_with_carrier_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
    }

    public function test_seller_fault_still_records_the_declared_fixed_mode(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_records_declared_mode_even_when_not_charged
     */
    public function test_seller_fault_still_records_the_declared_original_policy_mode(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    public function test_carrier_fault_with_original_policy_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_records_declared_mode_even_when_not_charged
     */
    public function test_original_policy_return_with_carrier_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_records_declared_mode_even_when_not_charged
     */
    public function test_original_policy_exchange_with_seller_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
    }

    public function test_seller_fault_group_basis_carries_the_reason(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertCount(1, $result->groups);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->groups[0]['reason']);
    }

    public function test_seller_fault_group_basis_has_zero_fee_and_unit_multiplier(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->groups[0]['fee']);
        $this->assertSame(1, $result->groups[0]['multiplier']);
    }

    /**
     * DoD 교차곱 — 부과정책 6종 × 귀책 3종 × 클레임 유형 2종 (36셀).
     *
     * 한 행이 세 귀책을 함께 단언한다: 판매자·배송사는 0원이고, **같은 정책·같은 신청**을
     * 고객 귀책으로 태우면 정책대로 청구된다(대조군). 대조군이 없으면 "0원" 이 게이트 덕분인지
     * 정책이 원래 0원이라 그런지 구분되지 않아, `free`·`조건부무료 충족` 행이 사문이 된다.
     *
     * 모드를 `original_policy` 로 고정한 이유: 이 모드에서만 부과정책이 실제로 계산에 쓰이므로,
     * 귀책 게이트가 계산보다 **먼저** 발화한다는 사실이 여기서만 증명 가능하다.
     *
     * @param  string  $policyKey  부과정책 픽스처 키
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  ClaimReasonFaultTypeEnum  $fault  청구하지 않는 귀책
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault
     */
    #[DataProvider('faultByChargePolicyMatrixProvider')]
    public function test_non_customer_fault_is_zero_for_every_charge_policy(
        string $policyKey,
        ClaimTypeEnum $claimType,
        ClaimReasonFaultTypeEnum $fault,
    ): void {
        $fixture = self::chargePolicyFixture($policyKey);

        [$order, $option] = $this->scenario(
            array_merge($fixture['country'], ['claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY]),
            $fixture['option'],
        );

        $result = $this->calc($order, $claimType, $this->items($option, 2), $fault);

        $this->assertSame(0, $result->fee);
        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);

        // 대조군: 고객 귀책이면 같은 정책이 실제로 계산되어 반품 ×1 · 교환 ×2 가 나온다.
        $expected = $fixture['one_way'] * ($claimType === ClaimTypeEnum::EXCHANGE ? 2 : 1);
        $control = $this->calc($order, $claimType, $this->items($option, 2), ClaimReasonFaultTypeEnum::CUSTOMER);

        $this->assertSame($expected, $control->fee);
        $this->assertTrue($control->charged);
    }

    /**
     * 부과정책 6종 × 클레임 유형 2종 × 비-고객 귀책 2종 (고객 귀책은 각 행의 대조군).
     *
     * @return array<string, array{string, ClaimTypeEnum, ClaimReasonFaultTypeEnum}> 데이터 세트
     */
    public static function faultByChargePolicyMatrixProvider(): array
    {
        $types = ['반품' => ClaimTypeEnum::RETURN, '교환' => ClaimTypeEnum::EXCHANGE];
        $faults = [
            '판매자귀책' => ClaimReasonFaultTypeEnum::SELLER,
            '배송사귀책' => ClaimReasonFaultTypeEnum::CARRIER,
        ];

        $sets = [];

        foreach (self::chargePolicyKeys() as $policyKey) {
            foreach ($types as $typeLabel => $claimType) {
                foreach ($faults as $faultLabel => $fault) {
                    $sets[$policyKey.' · '.$typeLabel.' · '.$faultLabel] = [$policyKey, $claimType, $fault];
                }
            }
        }

        return $sets;
    }

    public function test_customer_fault_result_reason_is_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFee::REASON_CHARGED, $result->reason);
    }

    // ================================================================
    // 3. fixed 모드 — 정책에 입력된 금액을 그대로 쓴다 (D11)
    // ================================================================

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_fixed_mode_uses_the_declared_amount
     */
    public function test_fixed_mode_return_uses_return_fixed_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 2500, 'exchange_fixed_fee' => 7000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2500, $result->fee);
    }

    /**
     * @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_fixed_mode_does_not_double_for_an_exchange
     */
    public function test_fixed_mode_exchange_uses_exchange_fixed_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 2500, 'exchange_fixed_fee' => 7000]);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(7000, $result->fee);
    }

    public function test_fixed_mode_exchange_does_not_double_the_entered_amount(): void
    {
        // 교환 고정액은 화면에서 '왕복 총액'으로 입력받으므로 배수를 다시 곱하지 않는다.
        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000, 'exchange_fixed_fee' => 6000]);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(6000, $result->fee);
        $this->assertSame(1, $result->groups[0]['multiplier']);
    }

    public function test_fixed_mode_exchange_fee_need_not_be_double_the_return_fee(): void
    {
        // 반품비·교환비는 별도 입력이라 2배 관계를 강제하지 않는다(D11).
        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000, 'exchange_fixed_fee' => 4000]);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(4000, $result->fee);
    }

    public function test_fixed_mode_zero_fee_is_still_a_charged_result(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 0]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_CHARGED, $result->reason);
    }

    public function test_fixed_mode_rounds_decimal_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 2500.50]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2501, $result->fee);
    }

    /**
     * `fixed` 모드는 부과정책 6종 어느 것에서도 정책 입력값만 쓴다 (유형 2종 전수).
     *
     * @param  string  $policyKey  부과정책 픽스처 키
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  int  $expected  기대 청구액
     *
     * @effects claim_shipping_fee_fixed_mode_ignores_the_charge_policy
     */
    #[DataProvider('fixedModePolicyIndependenceProvider')]
    public function test_fixed_mode_fee_is_independent_of_the_charge_policy(
        string $policyKey,
        ClaimTypeEnum $claimType,
        int $expected,
    ): void {
        $fixture = self::chargePolicyFixture($policyKey);

        [$order, $option] = $this->scenario(
            array_merge($fixture['country'], [
                'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
                'return_fixed_fee' => 2000,
                'exchange_fixed_fee' => 3500,
            ]),
            $fixture['option'],
        );

        $result = $this->calc($order, $claimType, $this->items($option, 2));

        $this->assertSame($expected, $result->fee);
        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    /**
     * 부과정책 6종 × 클레임 유형 2종.
     *
     * @return array<string, array{string, ClaimTypeEnum, int}> 데이터 세트
     */
    public static function fixedModePolicyIndependenceProvider(): array
    {
        $sets = [];

        foreach (self::chargePolicyKeys() as $policyKey) {
            $sets[$policyKey.' · 반품'] = [$policyKey, ClaimTypeEnum::RETURN, 2000];
            $sets[$policyKey.' · 교환'] = [$policyKey, ClaimTypeEnum::EXCHANGE, 3500];
        }

        return $sets;
    }

    public function test_fixed_mode_basis_has_no_charge_policy(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertNull($result->groups[0]['charge_policy']);
    }

    public function test_fixed_mode_basis_records_fixed_mode(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame('fixed', $result->groups[0]['mode']);
        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    // ================================================================
    // 4. original_policy 모드 × 부과정책 6종 × 유형 2
    // ================================================================

    /**
     * @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_original_policy_return_is_one_way
     */
    public function test_original_policy_free_return_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FREE, ['base_fee' => 0]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_original_policy_exchange_is_round_trip
     */
    public function test_original_policy_free_exchange_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FREE, ['base_fee' => 0]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_original_policy_return_is_one_way
     */
    public function test_original_policy_fixed_return_is_one_way(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_original_policy_exchange_is_round_trip
     */
    public function test_original_policy_fixed_exchange_is_round_trip(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(6000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge
     */
    public function test_original_policy_conditional_free_unmet_return_charges_base_fee(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 50000]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(2500, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge
     */
    public function test_original_policy_conditional_free_unmet_exchange_doubles_base_fee(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 50000]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 1));

        $this->assertSame(5000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge
     */
    public function test_original_policy_conditional_free_met_return_is_zero(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 10000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge
     */
    public function test_original_policy_conditional_free_met_exchange_is_zero(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 10000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=per_quantity, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_per_quantity_scales_with_the_claim_quantity
     */
    public function test_original_policy_per_quantity_return_scales_with_claim_quantity(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 3));

        $this->assertSame(3000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=per_quantity, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_per_quantity_scales_with_the_claim_quantity
     */
    public function test_original_policy_per_quantity_exchange_doubles(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 3));

        $this->assertSame(6000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=range_amount, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_range_tier_matches_the_claim_subtotal
     */
    public function test_original_policy_range_amount_return_uses_matching_tier(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
                'ranges' => ['tiers' => [['max' => 30000, 'fee' => 4000], ['max' => null, 'fee' => 0]]],
            ]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(4000, $result->fee);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=range_amount, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_range_tier_matches_the_claim_subtotal
     */
    public function test_original_policy_range_amount_exchange_doubles_matching_tier(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
                'ranges' => ['tiers' => [['max' => 30000, 'fee' => 4000], ['max' => null, 'fee' => 0]]],
            ]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 1));

        $this->assertSame(8000, $result->fee);
    }

    // ================================================================
    // 5. original_policy 세부 판정
    // ================================================================

    public function test_original_policy_fixed_rounds_decimal_base_fee(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 2500.50]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2501, $result->fee);
    }

    public function test_original_policy_conditional_free_threshold_exactly_met_is_free(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 20000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_conditional_free_one_won_short_is_charged(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 20001]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(2500, $result->fee);
    }

    public function test_partial_quantity_can_fall_below_the_free_threshold(): void
    {
        // 전량이면 40,000 으로 무료지만 1개만 신청하면 20,000 이라 유료가 된다.
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 30000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(2500, $result->fee);
    }

    public function test_full_quantity_can_meet_the_free_threshold(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 30000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_per_quantity_rounds_up_partial_units(): void
    {
        // unit_value 2 · 수량 3 → ceil(3/2) = 2 단위
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1500, 'ranges' => ['unit_value' => 2]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 3));

        $this->assertSame(3000, $result->fee);
    }

    public function test_original_policy_per_quantity_single_unit(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1500, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(1500, $result->fee);
    }

    public function test_original_policy_range_amount_first_tier(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
                'ranges' => ['tiers' => [
                    ['max' => 10000, 'fee' => 5000],
                    ['max' => 50000, 'fee' => 2500],
                    ['max' => null, 'fee' => 0],
                ]],
            ]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(5000, $result->fee);
    }

    public function test_original_policy_range_amount_middle_tier(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
                'ranges' => ['tiers' => [
                    ['max' => 10000, 'fee' => 5000],
                    ['max' => 50000, 'fee' => 2500],
                    ['max' => null, 'fee' => 0],
                ]],
            ]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 3));

        $this->assertSame(2500, $result->fee);
    }

    public function test_original_policy_range_amount_unlimited_tier(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
                'ranges' => ['tiers' => [
                    ['max' => 10000, 'fee' => 5000],
                    ['max' => null, 'fee' => 1000],
                ]],
            ]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 4));

        $this->assertSame(1000, $result->fee);
    }

    public function test_original_policy_range_with_empty_tiers_is_zero(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, ['ranges' => ['tiers' => []]]),
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_range_quantity_uses_claim_quantity(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::RANGE_QUANTITY, [
                'ranges' => ['tiers' => [
                    ['max' => 2, 'fee' => 3000],
                    ['max' => null, 'fee' => 6000],
                ]],
            ]),
            ['quantity' => 5, 'subtotal_price' => 50000],
        );

        $charged = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));
        $overflow = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 4));

        $this->assertSame(3000, $charged->fee);
        $this->assertSame(6000, $overflow->fee);
    }

    public function test_original_policy_basis_records_the_charge_policy(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, [
            'base_fee' => 2500,
            'free_threshold' => 500000,
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame('conditional_free', $result->groups[0]['charge_policy']);
    }

    public function test_original_policy_basis_base_fee_is_the_one_way_amount(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(3000, $result->groups[0]['base_fee']);
        $this->assertSame(6000, $result->groups[0]['fee']);
    }

    public function test_original_policy_exchange_multiplier_is_two(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(2, $result->groups[0]['multiplier']);
    }

    public function test_original_policy_return_multiplier_is_one(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(1, $result->groups[0]['multiplier']);
    }

    public function test_original_policy_uses_discounted_amount_not_gross_subtotal(): void
    {
        // 소계 40,000 에서 상품쿠폰 15,000 · 할인코드 5,000 을 뺀 20,000 이 판정 기준이다.
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 30000]),
            [
                'quantity' => 2,
                'subtotal_price' => 40000,
                'product_coupon_discount_amount' => 15000,
                'code_discount_amount' => 5000,
            ],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(2500, $result->fee);
    }

    public function test_original_policy_ignores_order_coupon_share_like_order_shipping_does(): void
    {
        // 주문쿠폰 안분액은 주문 배송비 판정에서도 제외된다 — 클레임도 같은 기준이어야 한다.
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 30000]),
            [
                'quantity' => 2,
                'subtotal_price' => 40000,
                'order_coupon_discount_amount' => 20000,
            ],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    // ================================================================
    // 6. 헤더 모드 결정
    // ================================================================

    public function test_header_mode_is_fixed_for_a_single_fixed_group(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    public function test_header_mode_is_original_policy_for_a_single_original_group(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
    }

    public function test_header_mode_is_fixed_when_every_group_is_fixed(): void
    {
        $order = $this->makeOrder();
        $first = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 1000]));
        $second = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 2000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    public function test_header_mode_is_original_policy_when_every_group_is_original(): void
    {
        $order = $this->makeOrder();
        $first = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 1000])));
        $second = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 2000])));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
    }

    /**
     * @effects claim_shipping_fee_header_mode_falls_back_to_original_policy_when_mixed
     */
    public function test_header_mode_is_original_policy_when_modes_are_mixed(): void
    {
        $order = $this->makeOrder();
        $fixed = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 1000]));
        $original = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 2000])));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $fixed->id, 'cancel_quantity' => 1],
            ['order_option_id' => $original->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
    }

    public function test_mixed_modes_keep_each_group_mode_in_the_basis(): void
    {
        $order = $this->makeOrder();
        $fixed = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 1000]));
        $original = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 2000])));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $fixed->id, 'cancel_quantity' => 1],
            ['order_option_id' => $original->id, 'cancel_quantity' => 1],
        ]);

        $modes = array_column($result->groups, 'mode');
        sort($modes);

        $this->assertSame(['fixed', 'original_policy'], $modes);
    }

    // ================================================================
    // 7. 다중 정책 그룹 합산
    // ================================================================

    public function test_two_fixed_policies_are_summed(): void
    {
        $order = $this->makeOrder();
        $first = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 1000]));
        $second = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 2500]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(3500, $result->fee);
        $this->assertCount(2, $result->groups);
    }

    public function test_two_original_policies_are_summed_with_exchange_multiplier(): void
    {
        $order = $this->makeOrder();
        $first = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 1000])));
        $second = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 2000])));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(6000, $result->fee);
    }

    /**
     * @effects claim_shipping_fee_groups_are_summed_across_policies
     */
    public function test_mixed_mode_groups_are_summed(): void
    {
        $order = $this->makeOrder();
        $fixed = $this->makeOption($order, $this->makePolicy(['exchange_fixed_fee' => 4000]));
        $original = $this->makeOption($order, $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 1500])));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, [
            ['order_option_id' => $fixed->id, 'cancel_quantity' => 1],
            ['order_option_id' => $original->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(7000, $result->fee);
    }

    public function test_same_policy_different_country_forms_separate_groups(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 1000]);
        $policy->countrySettings()->create([
            'country_code' => 'US',
            'shipping_method' => 'parcel',
            'currency_code' => 'USD',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => 0,
            'claim_fee_mode' => ClaimShippingFeeModeEnum::FIXED,
            'return_fixed_fee' => 7000,
            'exchange_fixed_fee' => 9000,
            'is_active' => true,
        ]);

        $kr = $this->makeOption($order, $policy, [], 'KR');
        $us = $this->makeOption($order, $policy, [], 'US');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $kr->id, 'cancel_quantity' => 1],
            ['order_option_id' => $us->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(8000, $result->fee);
        $this->assertCount(2, $result->groups);
    }

    public function test_same_policy_and_country_forms_one_group(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 1000]);
        $first = $this->makeOption($order, $policy);
        $second = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(1000, $result->fee);
        $this->assertCount(1, $result->groups);
    }

    public function test_one_group_amount_accumulates_across_options(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, [
            'base_fee' => 2500,
            'free_threshold' => 30000,
        ]));
        $first = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 20000]);
        $second = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 20000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(0, $result->fee);
        $this->assertSame(40000, $result->groups[0]['total_amount']);
    }

    public function test_unresolvable_group_does_not_block_the_resolvable_one(): void
    {
        $order = $this->makeOrder();
        $good = $this->makeOption($order, $this->makePolicy(['return_fixed_fee' => 1200]));
        $bad = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $good->id, 'cancel_quantity' => 1],
            ['order_option_id' => $bad->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(1200, $result->fee);
        $this->assertTrue($result->charged);
    }

    // ================================================================
    // 8. 대상 아이템 정규화
    // ================================================================

    public function test_empty_items_produce_no_items_reason(): void
    {
        [$order] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, []);

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    public function test_zero_quantity_item_is_dropped(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 0));

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    public function test_negative_quantity_item_is_dropped(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, -3));

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    public function test_unknown_order_option_id_is_dropped(): void
    {
        [$order] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => 999999, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    public function test_option_belonging_to_another_order_is_dropped(): void
    {
        [$order] = $this->scenario();
        [, $foreign] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($foreign, 1));

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    public function test_missing_quantity_key_is_dropped(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $option->id],
        ]);

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
    }

    /**
     * @effects claim_shipping_fee_quantity_is_clamped_to_the_ordered_amount
     */
    public function test_quantity_above_the_ordered_amount_is_clamped(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 99));

        $this->assertSame(2000, $result->fee);
    }

    public function test_valid_and_invalid_items_keep_only_the_valid_one(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 1800]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
            ['order_option_id' => 999999, 'cancel_quantity' => 5],
            ['order_option_id' => $option->id, 'cancel_quantity' => 0],
        ]);

        $this->assertSame(1800, $result->fee);
        $this->assertSame(1, $result->groups[0]['item_count']);
    }

    public function test_clamped_quantity_is_reflected_in_the_basis(): void
    {
        [$order, $option] = $this->scenario([], ['quantity' => 3, 'subtotal_price' => 30000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 10));

        $this->assertSame(3, $result->groups[0]['total_quantity']);
    }

    public function test_clamped_quantity_is_reflected_in_the_threshold_decision(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 60000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        // 요청은 10개지만 주문 수량 2개로 잘려 40,000 이므로 임계 60,000 에 미달한다.
        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 10));

        $this->assertSame(2500, $result->fee);
    }

    // ================================================================
    // 9. 정책 미해석 — 0원 처리
    // ================================================================

    public function test_missing_shipping_row_yields_no_shipping_policy(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null, [], 'KR', withShipping: false);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFee::REASON_NO_SHIPPING_POLICY, $result->reason);
    }

    public function test_null_shipping_policy_id_yields_no_shipping_policy(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFee::REASON_NO_SHIPPING_POLICY, $result->reason);
    }

    public function test_missing_country_setting_yields_no_shipping_policy(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy([], 'KR');
        $option = $this->makeOption($order, $policy, [], 'JP');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFee::REASON_NO_SHIPPING_POLICY, $result->reason);
    }

    public function test_deleted_policy_yields_no_shipping_policy(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy();
        $option = $this->makeOption($order, $policy);
        $policy->countrySettings()->delete();
        $policy->delete();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFee::REASON_NO_SHIPPING_POLICY, $result->reason);
    }

    /**
     * @effects claim_shipping_fee_unresolvable_policy_is_not_charged
     */
    public function test_unresolvable_policy_is_not_a_charged_result(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertFalse($result->charged);
        $this->assertSame(0, $result->fee);
    }

    public function test_unresolvable_policy_records_fixed_mode(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
    }

    // ================================================================
    // 10. 근거 스냅샷 계약
    // ================================================================

    public function test_to_array_exposes_the_snapshot_contract(): void
    {
        [$order, $option] = $this->scenario();

        $snapshot = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option))->toArray();

        $this->assertSame(
            ['fee', 'extra_fee', 'mc_fee', 'mode', 'claim_type', 'fault_type', 'charged', 'reason', 'redelivery_changed', 'groups'],
            array_keys($snapshot)
        );
    }

    public function test_to_array_values_match_the_result(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 2200]);

        $snapshot = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option))->toArray();

        $this->assertSame(2200, $snapshot['fee']);
        $this->assertSame('fixed', $snapshot['mode']);
        $this->assertSame('return', $snapshot['claim_type']);
        $this->assertSame('customer', $snapshot['fault_type']);
        $this->assertTrue($snapshot['charged']);
        $this->assertSame(ClaimShippingFee::REASON_CHARGED, $snapshot['reason']);
    }

    public function test_group_basis_exposes_the_expected_keys(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(
            [
                'shipping_policy_id', 'country_code', 'mode', 'charge_policy',
                'policy_source', 'hook_overridden', 'api_fallback',
                'base_fee', 'multiplier', 'extra_fee', 'extra_fee_legs',
                'fee', 'reason', 'item_count', 'total_amount', 'total_quantity', 'items',
            ],
            array_keys($result->groups[0])
        );
    }

    public function test_not_charged_snapshot_keeps_the_fault_type(): void
    {
        [$order, $option] = $this->scenario();

        $snapshot = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER)
            ->toArray();

        $this->assertSame('seller', $snapshot['fault_type']);
        $this->assertSame('exchange', $snapshot['claim_type']);
        $this->assertFalse($snapshot['charged']);
    }

    public function test_basis_totals_reflect_the_claim_not_the_whole_order(): void
    {
        [$order, $option] = $this->scenario([], ['quantity' => 4, 'subtotal_price' => 40000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(10000, $result->groups[0]['total_amount']);
        $this->assertSame(1, $result->groups[0]['total_quantity']);
    }

    public function test_basis_records_the_shipping_policy_and_country(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy();
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame($policy->id, $result->groups[0]['shipping_policy_id']);
        $this->assertSame('KR', $result->groups[0]['country_code']);
    }

    // ================================================================
    // 11. 배송비 플러그인 훅 — 원 정책 기준 모드는 주문 배송비와 같은 훅을 탄다
    // ================================================================

    /**
     * @effects claim_shipping_fee_hook_can_override_the_original_policy_fee
     */
    public function test_original_policy_mode_lets_the_shipping_plugin_hook_override_the_fee(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.shipping.calculate_fee', fn () => 1234);

        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(1234, $result->fee);
    }

    public function test_shipping_plugin_hook_receives_the_claim_context(): void
    {
        // 훅을 쓰는 플러그인이 주문 배송비 호출과 클레임 배송비 호출을 구분할 수 있어야 한다.
        $captured = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($fee, $countrySetting, $group) use (&$captured) {
                $captured = $group['_claim_context'] ?? null;

                return $fee;
            }
        );

        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(['claim_type' => 'exchange', 'fee_mode' => 'original_policy'], $captured);
    }

    public function test_hook_overridden_fee_is_recorded_in_the_basis(): void
    {
        // 훅이 금액을 정하면 base_fee 는 부과정책의 산출값이 아니다. 근거가 그 사실을 담지 않으면
        // 스냅샷이 "고정 정책이 1,234 원을 냈다" 고 잘못 말한다.
        HookManager::addFilter('sirsoft-ecommerce.shipping.calculate_fee', fn () => 1234);

        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertTrue($result->groups[0]['hook_overridden']);
        $this->assertSame(1234, $result->groups[0]['base_fee']);
    }

    public function test_basis_marks_the_fee_as_not_hook_overridden_without_a_plugin(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertFalse($result->groups[0]['hook_overridden']);
        $this->assertSame(3000, $result->groups[0]['base_fee']);
    }

    public function test_fixed_mode_basis_is_never_hook_overridden(): void
    {
        // 고정액은 훅을 타지 않으므로 플러그인이 붙어 있어도 근거는 거짓이어야 한다.
        HookManager::addFilter('sirsoft-ecommerce.shipping.calculate_fee', fn () => 1234);

        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertFalse($result->groups[0]['hook_overridden']);
        $this->assertSame(3000, $result->fee);
    }

    public function test_hook_overridden_flag_does_not_leak_across_policy_groups(): void
    {
        // 훅이 개입한 그룹과 아닌 그룹이 한 신청에 섞이면 근거가 그룹마다 달라야 한다.
        $order = $this->makeOrder();
        $hooked = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));
        $fixed = $this->makePolicy(['return_fixed_fee' => 2000]);
        $first = $this->makeOption($order, $hooked);
        $second = $this->makeOption($order, $fixed);

        HookManager::addFilter('sirsoft-ecommerce.shipping.calculate_fee', fn () => 1234);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertTrue($result->groups[0]['hook_overridden']);
        $this->assertFalse($result->groups[1]['hook_overridden']);
    }

    public function test_fixed_mode_does_not_call_the_shipping_plugin_hook(): void
    {
        // 고정액은 정책 입력값 그대로이므로 부과정책 계산 자체가 없다.
        $called = false;
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            function ($fee) use (&$called) {
                $called = true;

                return $fee;
            }
        );

        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertFalse($called);
        $this->assertSame(3000, $result->fee);
    }

    public function test_basis_item_count_matches_the_claimed_options(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy();
        $first = $this->makeOption($order, $policy);
        $second = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(2, $result->groups[0]['item_count']);
    }

    // ================================================================
    // 12. 부과정책 파라미터의 출처 — 주문 시점 스냅샷 우선
    // ================================================================

    /**
     * @effects claim_shipping_fee_charge_policy_comes_from_the_order_snapshot
     */
    public function test_original_policy_reads_the_charge_policy_from_the_order_snapshot(): void
    {
        $order = $this->makeOrder();
        // 현재 정책 행은 9,000원인데 주문 시점 스냅샷은 3,000원이다.
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
    }

    public function test_later_changes_to_the_live_fee_table_do_not_move_a_past_order(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 3000]));

        // 운영자가 요금표를 올린다.
        $policy->countrySettings->first()->update(['base_fee' => 12000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
    }

    public function test_snapshot_backed_group_records_the_order_snapshot_source(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy());

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeService::SOURCE_ORDER_SNAPSHOT, $result->groups[0]['policy_source']);
    }

    public function test_group_without_a_snapshot_records_the_live_policy_source(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeService::SOURCE_LIVE_POLICY, $result->groups[0]['policy_source']);
    }

    public function test_order_without_a_snapshot_falls_back_to_the_live_fee_table(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 4500]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(4500, $result->fee);
    }

    /**
     * @effects claim_shipping_fee_claim_terms_come_from_the_live_policy_row
     */
    public function test_claim_fee_mode_is_always_read_from_the_live_policy_row(): void
    {
        $order = $this->makeOrder();
        // 스냅샷은 claim_fee_mode 를 담지 않는다 — 모드는 현재 정책 행이 정한다.
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3300]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 3300]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame(3300, $result->fee);
    }

    public function test_fixed_fees_are_always_read_from_the_live_policy_row(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 4400]);
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        // 고정액 모드는 스냅샷의 base_fee 를 쓰지 않는다.
        $this->assertSame(4400, $result->fee);
    }

    public function test_deleted_live_country_row_is_not_recovered_from_the_snapshot(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 7000]));

        $policy->countrySettings()->delete();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertSame(ClaimShippingFee::REASON_NO_SHIPPING_POLICY, $result->reason);
    }

    public function test_snapshot_threshold_decides_the_conditional_free_result(): void
    {
        $order = $this->makeOrder();
        // 현재 정책은 임계 500,000(미달 → 유료), 주문 시점 스냅샷은 임계 10,000(충족 → 무료).
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, [
            'base_fee' => 2500,
            'free_threshold' => 500000,
        ]));
        $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'conditional_free',
            'base_fee' => 2500,
            'free_threshold' => 10000,
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    public function test_snapshot_ranges_decide_the_matching_tier(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::RANGE_AMOUNT, [
            'base_fee' => 0,
            'ranges' => ['tiers' => [['max' => null, 'fee' => 9900]]],
        ]));
        $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'range_amount',
            'base_fee' => 0,
            'ranges' => ['tiers' => [['max' => 50000, 'fee' => 4000], ['max' => null, 'fee' => 0]]],
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(4000, $result->fee);
    }

    public function test_snapshot_per_quantity_unit_value_is_used(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, [
            'base_fee' => 5000,
            'ranges' => ['unit_value' => 1],
        ]));
        $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'per_quantity',
            'base_fee' => 1000,
            'ranges' => ['unit_value' => 1],
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(2000, $result->fee);
    }

    public function test_exchange_multiplier_applies_to_the_snapshot_base_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(6000, $result->fee);
        $this->assertSame(3000, $result->groups[0]['base_fee']);
        $this->assertSame(2, $result->groups[0]['multiplier']);
    }

    public function test_snapshot_without_a_charge_policy_falls_back_to_the_live_row(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 5500]));
        // 정책이 해석되지 않은 주문의 스냅샷은 국가코드만 담는다.
        $option = $this->makeOption($order, $policy, [], 'KR', true, ['country_code' => 'KR']);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(5500, $result->fee);
        $this->assertSame(ClaimShippingFeeService::SOURCE_LIVE_POLICY, $result->groups[0]['policy_source']);
    }

    public function test_unresolvable_group_has_no_policy_source(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertNull($result->groups[0]['policy_source']);
    }

    // ================================================================
    // 13. 도서산간 추가배송비
    // ================================================================

    public function test_remote_area_fee_is_added_to_a_return(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(6000, $result->fee);
        $this->assertSame(3000, $result->extraFee);
    }

    /**
     * @effects claim_shipping_fee_remote_area_fee_is_added_per_leg
     */
    public function test_remote_area_fee_is_charged_on_both_legs_of_an_exchange(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        // 기본 3,000 × 2 + 도서산간 3,000 × 2구간
        $this->assertSame(12000, $result->fee);
        $this->assertSame(6000, $result->extraFee);
    }

    public function test_non_remote_zipcode_adds_nothing(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '06234');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
        $this->assertSame(0, $result->extraFee);
    }

    public function test_disabled_remote_area_setting_adds_nothing(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, [
            'extra_fee_enabled' => false,
            'extra_fee_settings' => [['zipcode' => '63000-63999', 'fee' => 3000]],
        ]));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->extraFee);
    }

    public function test_missing_zipcode_adds_nothing(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, null);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->extraFee);
    }

    public function test_remote_area_fee_is_korea_only(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()), 'JP');
        $option = $this->makeOption($order, $policy, [], 'JP');
        $this->withDeliveryAddress($order, '63001', 'JP');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->extraFee);
    }

    public function test_remote_area_fee_uses_a_wildcard_pattern(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(2500, '63*')));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63555');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2500, $result->extraFee);
    }

    public function test_remote_area_multiply_scales_with_the_charge_units(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, array_merge(
            ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]],
            $this->remoteArea(3000, '63000-63999', true),
        )));
        $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000]);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        // 수량당 1,000 × 2 + 도서산간 3,000 × 2단위
        $this->assertSame(8000, $result->fee);
        $this->assertSame(6000, $result->extraFee);
    }

    /**
     * @effects claim_shipping_fee_remote_area_multiply_follows_the_claim_quantity
     */
    public function test_remote_area_multiply_follows_the_claim_quantity_not_the_order_quantity(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, array_merge(
            ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]],
            $this->remoteArea(3000, '63000-63999', true),
        )));
        $option = $this->makeOption($order, $policy, ['quantity' => 4, 'subtotal_price' => 80000]);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(3000, $result->extraFee);
    }

    public function test_remote_area_multiply_is_ignored_for_non_per_unit_policies(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, array_merge(
            ['base_fee' => 3000],
            $this->remoteArea(3000, '63000-63999', true),
        )));
        $option = $this->makeOption($order, $policy, ['quantity' => 3, 'subtotal_price' => 60000]);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 3));

        $this->assertSame(3000, $result->extraFee);
    }

    /**
     * @effects claim_shipping_fee_remote_area_is_skipped_in_fixed_mode
     */
    public function test_fixed_mode_does_not_add_the_remote_area_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(array_merge(['return_fixed_fee' => 3000], $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
        $this->assertSame(0, $result->extraFee);
    }

    public function test_seller_fault_does_not_add_the_remote_area_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
        $this->assertSame(0, $result->extraFee);
    }

    public function test_remote_area_settings_come_from_the_order_snapshot(): void
    {
        $order = $this->makeOrder();
        // 현재 정책은 도서산간을 껐지만 주문 시점에는 켜져 있었다.
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['extra_fee_enabled' => false]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy([
            'extra_fee_enabled' => true,
            'extra_fee_settings' => [['zipcode' => '63000-63999', 'fee' => 3000]],
        ]));
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->extraFee);
    }

    public function test_basis_records_the_extra_fee_legs_for_a_return(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(['collection' => 3000, 'redelivery' => null], $result->groups[0]['extra_fee_legs']);
    }

    public function test_basis_records_the_extra_fee_legs_for_an_exchange(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(['collection' => 3000, 'redelivery' => 3000], $result->groups[0]['extra_fee_legs']);
    }

    public function test_basis_extra_fee_is_the_sum_of_the_legs(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(2000)));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(4000, $result->groups[0]['extra_fee']);
    }

    public function test_extra_fee_totals_across_two_policy_groups(): void
    {
        $order = $this->makeOrder();
        $first = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(1000)));
        $second = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(2000)));
        $firstOption = $this->makeOption($order, $first);
        $secondOption = $this->makeOption($order, $second);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $firstOption->id, 'cancel_quantity' => 1],
            ['order_option_id' => $secondOption->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(3000, $result->extraFee);
        $this->assertSame(9000, $result->fee);
    }

    public function test_zipcode_falls_back_to_the_order_shipping_address(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);

        // 배송지 스냅샷이 없는 과거 주문은 주문 배송주소로 폴백한다.
        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_country_code' => 'KR',
            'zipcode' => '63001',
        ]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->extraFee);
    }

    public function test_delivery_address_snapshot_wins_over_the_current_shipping_address(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_country_code' => 'KR',
            'zipcode' => '63001',
        ]);
        // 주문 시점에는 도서산간이 아닌 곳으로 배송되었다.
        $this->withDeliveryAddress($order, '06234');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->extraFee);
    }

    public function test_unresolvable_group_records_empty_extra_fee_legs(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOption($order, null);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(['collection' => 0, 'redelivery' => 0], $result->groups[0]['extra_fee_legs']);
    }

    // ================================================================
    // 14. 재발송지 변경 재판정 (D14)
    // ================================================================

    public function test_changed_redelivery_zipcode_is_used_for_the_second_leg(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '06234'],
        );

        // 회수 구간만 도서산간이다.
        $this->assertSame(['collection' => 3000, 'redelivery' => 0], $result->groups[0]['extra_fee_legs']);
        $this->assertSame(9000, $result->fee);
    }

    /**
     * @effects claim_shipping_fee_redelivery_address_change_reprices_the_second_leg
     */
    public function test_moving_the_redelivery_into_a_remote_area_adds_the_second_leg(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '06234');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '63001'],
        );

        $this->assertSame(['collection' => 0, 'redelivery' => 3000], $result->groups[0]['extra_fee_legs']);
        $this->assertSame(9000, $result->fee);
    }

    public function test_unchanged_redelivery_address_keeps_both_legs(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '63001'],
        );

        $this->assertSame(6000, $result->extraFee);
    }

    public function test_redelivery_address_without_a_zipcode_keeps_the_original(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR'],
        );

        $this->assertSame(6000, $result->extraFee);
    }

    public function test_redelivery_address_is_ignored_for_a_return(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::RETURN,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '06234'],
        );

        $this->assertSame(['collection' => 3000, 'redelivery' => null], $result->groups[0]['extra_fee_legs']);
    }

    public function test_redelivery_to_another_country_drops_the_korea_only_extra_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'JP', 'zipcode' => '63001'],
        );

        $this->assertSame(['collection' => 3000, 'redelivery' => 0], $result->groups[0]['extra_fee_legs']);
    }

    public function test_redelivery_change_does_not_move_the_base_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '06234'],
        );

        $this->assertSame(3000, $result->groups[0]['base_fee']);
        $this->assertSame(2, $result->groups[0]['multiplier']);
    }

    public function test_redelivery_change_is_ignored_without_a_remote_area_setting(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '06234'],
        );

        $this->assertSame(6000, $result->fee);
        $this->assertSame(0, $result->extraFee);
    }

    public function test_fixed_mode_ignores_the_redelivery_address(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(array_merge(['exchange_fixed_fee' => 5000], $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '06234');

        $result = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '63001'],
        );

        $this->assertSame(5000, $result->fee);
    }

    public function test_null_redelivery_address_behaves_like_no_change(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $withNull = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));
        $withSame = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '63001'],
        );

        $this->assertSame($withSame->fee, $withNull->fee);
    }

    // ================================================================
    // 15. 수량 안분
    // ================================================================

    public function test_single_item_takes_the_whole_group_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertCount(1, $result->groups[0]['items']);
        $this->assertSame(3000, $result->groups[0]['items'][0]['fee']);
    }

    /**
     * @effects claim_shipping_fee_group_fee_is_apportioned_across_items
     */
    public function test_group_fee_is_apportioned_by_amount(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $cheap = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $pricey = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 30000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $cheap->id, 'cancel_quantity' => 1],
            ['order_option_id' => $pricey->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(750, $result->groups[0]['items'][0]['fee']);
        $this->assertSame(2250, $result->groups[0]['items'][1]['fee']);
    }

    /**
     * @effects claim_shipping_fee_apportioned_shares_sum_to_the_group_fee
     */
    public function test_apportioned_shares_sum_to_the_group_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 1000]);
        $first = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $second = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $third = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
            ['order_option_id' => $third->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(1000, array_sum(array_column($result->groups[0]['items'], 'fee')));
    }

    public function test_apportionment_remainder_lands_on_the_last_item(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 1000]);
        $first = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $second = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $third = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
            ['order_option_id' => $third->id, 'cancel_quantity' => 1],
        ]);

        $fees = array_column($result->groups[0]['items'], 'fee');

        $this->assertSame([333, 333, 334], $fees);
    }

    public function test_zero_fee_apportions_to_zero(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 0]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->groups[0]['items'][0]['fee']);
    }

    public function test_free_items_are_apportioned_evenly(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(['return_fixed_fee' => 1000]);
        $first = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 0]);
        $second = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 0]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame([500, 500], array_column($result->groups[0]['items'], 'fee'));
    }

    public function test_apportioned_item_carries_the_order_option_id(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame($option->id, $result->groups[0]['items'][0]['order_option_id']);
        $this->assertSame($option->product_option_id, $result->groups[0]['items'][0]['product_option_id']);
    }

    public function test_apportioned_item_carries_the_claim_quantity_not_the_ordered_quantity(): void
    {
        [$order, $option] = $this->scenario([], ['quantity' => 4, 'subtotal_price' => 40000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(1, $result->groups[0]['items'][0]['quantity']);
        $this->assertSame(10000, $result->groups[0]['items'][0]['subtotal']);
    }

    public function test_apportioned_item_exposes_the_expected_keys(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(
            ['order_option_id', 'product_option_id', 'quantity', 'subtotal', 'fee'],
            array_keys($result->groups[0]['items'][0])
        );
    }

    public function test_apportionment_includes_the_remote_area_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(6000, $result->groups[0]['items'][0]['fee']);
    }

    public function test_each_policy_group_apportions_its_own_fee(): void
    {
        $order = $this->makeOrder();
        $first = $this->makePolicy(['return_fixed_fee' => 1000]);
        $second = $this->makePolicy(['return_fixed_fee' => 2000]);
        $firstOption = $this->makeOption($order, $first);
        $secondOption = $this->makeOption($order, $second);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $firstOption->id, 'cancel_quantity' => 1],
            ['order_option_id' => $secondOption->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(1000, $result->groups[0]['items'][0]['fee']);
        $this->assertSame(2000, $result->groups[1]['items'][0]['fee']);
    }

    public function test_not_charged_result_apportions_to_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->groups[0]['items'][0]['fee']);
    }

    // ================================================================
    // 16. 다통화 청구액 (mc_claim_shipping_fee)
    // ================================================================

    /**
     * @effects claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate
     */
    public function test_multi_currency_fee_uses_the_order_snapshot_rate(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->mcFee['KRW']);
        $this->assertSame(2.25, $result->mcFee['USD']);
    }

    public function test_multi_currency_fee_covers_every_snapshot_currency(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(['KRW', 'USD'], array_keys($result->mcFee));
    }

    public function test_multi_currency_fee_follows_the_exchange_multiplier(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(6000, $result->mcFee['KRW']);
        $this->assertSame(4.5, $result->mcFee['USD']);
    }

    public function test_multi_currency_fee_includes_the_remote_area_fee(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(1000)));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(4000, $result->mcFee['KRW']);
        $this->assertSame(3.0, $result->mcFee['USD']);
    }

    public function test_multi_currency_fee_is_zero_for_a_seller_fault(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->mcFee['KRW']);
        $this->assertSame(0.0, $result->mcFee['USD']);
    }

    public function test_multi_currency_fee_is_present_for_a_non_chargeable_claim_type(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy();
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option));

        $this->assertSame(['KRW', 'USD'], array_keys($result->mcFee));
    }

    /**
     * @effects claim_shipping_fee_multi_currency_is_empty_without_a_snapshot
     */
    public function test_multi_currency_fee_is_empty_without_a_currency_snapshot(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => null]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame([], $result->mcFee);
        $this->assertSame(3000, $result->fee);
    }

    public function test_multi_currency_fee_ignores_a_later_rate_change(): void
    {
        $snapshot = $this->currencySnapshot();
        $order = $this->makeOrder(['currency_snapshot' => $snapshot]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        // 현재 설정의 환율이 바뀌어도 주문 스냅샷 환율이 산정 근거다.
        $this->setupTestCurrencySettings();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2.25, $result->mcFee['USD']);
    }

    public function test_snapshot_exposes_the_multi_currency_fee(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);
        $policy = $this->makePolicy(['return_fixed_fee' => 3000]);
        $option = $this->makeOption($order, $policy);

        $snapshot = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option))->toArray();

        $this->assertSame(2.25, $snapshot['mc_fee']['USD']);
    }

    public function test_snapshot_exposes_the_extra_fee_total(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea(1500)));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $snapshot = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option))->toArray();

        $this->assertSame(1500, $snapshot['extra_fee']);
        $this->assertSame(4500, $snapshot['fee']);
    }

    public function test_extra_fee_total_is_zero_without_a_remote_area(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->extraFee);
    }

    // ================================================================
    // 17. 외부 요금 API 부과정책 — 클레임 문맥에서는 호출하지 않는다
    // ================================================================

    /**
     * @scenario fee_mode=original_policy, charge_policy=api, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_api_policy_falls_back_to_the_base_fee
     */
    public function test_api_charge_policy_falls_back_to_the_base_fee(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::API, [
            'base_fee' => 2800,
            'api_endpoint' => 'https://carrier.example.com/rates',
        ]));
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2800, $result->fee);
    }

    /**
     * @effects claim_shipping_fee_api_policy_makes_no_external_request
     */
    public function test_api_charge_policy_does_not_dispatch_an_external_request(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::API, [
            'base_fee' => 2800,
            'api_endpoint' => 'https://carrier.example.com/rates',
        ]));
        $option = $this->makeOption($order, $policy);

        $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        Http::assertNothingSent();
    }

    public function test_api_charge_policy_from_the_order_snapshot_also_falls_back(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, [
            'base_fee' => 9000,
            'api_endpoint' => 'https://carrier.example.com/rates',
        ]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'api',
            'base_fee' => 2800,
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2800, $result->fee);
        Http::assertNothingSent();
    }

    public function test_api_fallback_is_recorded_in_the_basis(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::API, [
            'base_fee' => 2800,
            'api_endpoint' => 'https://carrier.example.com/rates',
        ]));
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertTrue($result->groups[0]['api_fallback']);
        $this->assertSame('api', $result->groups[0]['charge_policy']);
    }

    public function test_non_api_policies_are_not_marked_as_api_fallback(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertFalse($result->groups[0]['api_fallback']);
    }

    /**
     * @scenario fee_mode=original_policy, charge_policy=api, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
     *
     * @effects claim_shipping_fee_api_policy_falls_back_to_the_base_fee
     */
    public function test_api_charge_policy_doubles_for_an_exchange(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::API, [
            'base_fee' => 2800,
            'api_endpoint' => 'https://carrier.example.com/rates',
        ]));
        $option = $this->makeOption($order, $policy);

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(5600, $result->fee);
    }

    // ================================================================
    // 18. 확장 축 교차 회귀 (S2b1 본체와 S2b2 확장이 만나는 지점)
    // ================================================================

    public function test_hook_overridden_group_still_adds_the_remote_area_fee(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.shipping.calculate_fee',
            fn ($fee, $countrySetting, $group) => 5000,
            10,
            3
        );

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        // 주문 배송비 경로와 동형 — 훅은 기본 배송비만 정하고 도서산간은 그대로 얹힌다.
        $this->assertSame(8000, $result->fee);
        $this->assertTrue($result->groups[0]['hook_overridden']);
        $this->assertSame(3000, $result->groups[0]['extra_fee']);
    }

    public function test_fixed_mode_exchange_records_both_legs_as_zero(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy(array_merge(['exchange_fixed_fee' => 5000], $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(['collection' => 0, 'redelivery' => 0], $result->groups[0]['extra_fee_legs']);
    }

    /**
     * @effects claim_shipping_fee_basis_records_the_policy_source
     */
    public function test_policy_source_is_recorded_per_group(): void
    {
        $order = $this->makeOrder();
        $withSnapshot = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));
        $withoutSnapshot = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 1500]));
        $first = $this->makeOption($order, $withSnapshot, [], 'KR', true, $this->snapshotPolicy(['base_fee' => 2000]));
        $second = $this->makeOption($order, $withoutSnapshot);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, [
            ['order_option_id' => $first->id, 'cancel_quantity' => 1],
            ['order_option_id' => $second->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(ClaimShippingFeeService::SOURCE_ORDER_SNAPSHOT, $result->groups[0]['policy_source']);
        $this->assertSame(ClaimShippingFeeService::SOURCE_LIVE_POLICY, $result->groups[1]['policy_source']);
        $this->assertSame(3500, $result->fee);
    }

    public function test_no_items_result_still_carries_the_multi_currency_fee(): void
    {
        $order = $this->makeOrder(['currency_snapshot' => $this->currencySnapshot()]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, []);

        $this->assertSame(ClaimShippingFee::REASON_NO_ITEMS, $result->reason);
        $this->assertSame(['KRW', 'USD'], array_keys($result->mcFee));
    }

    public function test_redelivery_change_is_reflected_in_the_apportioned_item_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, $this->remoteArea()));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $remote = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));
        $moved = $this->calc(
            $order,
            ClaimTypeEnum::EXCHANGE,
            $this->items($option),
            ClaimReasonFaultTypeEnum::CUSTOMER,
            ['country_code' => 'KR', 'zipcode' => '06234'],
        );

        $this->assertSame(12000, $remote->groups[0]['items'][0]['fee']);
        $this->assertSame(9000, $moved->groups[0]['items'][0]['fee']);
    }

    public function test_api_policy_also_carries_the_remote_area_fee(): void
    {
        Http::fake();

        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::API, array_merge(
            ['base_fee' => 2800, 'api_endpoint' => 'https://carrier.example.com/rates'],
            $this->remoteArea(1200),
        )));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(4000, $result->fee);
        Http::assertNothingSent();
    }

    public function test_remote_area_multiply_setting_comes_from_the_order_snapshot(): void
    {
        $order = $this->makeOrder();
        // 현재 정책은 중복 부과를 껐지만 주문 시점에는 켜져 있었다.
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, array_merge(
            ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]],
            $this->remoteArea(3000, '63000-63999', false),
        )));
        $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'per_quantity',
            'base_fee' => 1000,
            'ranges' => ['unit_value' => 1],
            'extra_fee_enabled' => true,
            'extra_fee_multiply' => true,
            'extra_fee_settings' => [['zipcode' => '63000-63999', 'fee' => 3000]],
        ]));
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(6000, $result->extraFee);
    }
    // ================================================================
    // 19. 사후 비활성화된 국가 설정 (확정 2026-08-21)
    // ================================================================

    /**
     * @effects claim_shipping_fee_deactivated_country_row_still_charges
     */
    public function test_deactivated_live_country_row_still_charges_the_fixed_claim_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 3000, 'is_active' => false]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertTrue($result->charged);
        $this->assertSame(3000, $result->fee);
    }

    public function test_deactivated_live_country_row_still_charges_from_the_order_snapshot(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, [
            'base_fee' => 9000,
            'is_active' => false,
        ]));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy([
            'charge_policy' => 'fixed',
            'base_fee' => 4000,
        ]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertTrue($result->charged);
        // 부과정책은 주문 시점 스냅샷(4,000), 꺼진 현재 행의 9,000 이 아니다.
        $this->assertSame(4000, $result->fee);
        $this->assertSame(ClaimShippingFeeService::SOURCE_ORDER_SNAPSHOT, $result->groups[0]['policy_source']);
    }

    public function test_deactivated_live_country_row_still_adds_the_remote_area_fee(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, array_merge(
            ['base_fee' => 3000, 'is_active' => false],
            $this->remoteArea(3000),
        )));
        $option = $this->makeOption($order, $policy, [], 'KR', true, $this->snapshotPolicy(array_merge(
            ['charge_policy' => 'fixed', 'base_fee' => 3000],
            ['extra_fee_enabled' => true, 'extra_fee_settings' => [['zipcode' => '63000-63999', 'fee' => 3000]]],
        )));
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(6000, $result->fee);
        $this->assertSame(3000, $result->extraFee);
    }
    // ================================================================
    // 20. 재발송지 재판정 사실의 근거 기록 (D14 · 확정 2026-08-21)
    // ================================================================

    public function test_redelivery_change_is_recorded_in_the_snapshot(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, array_merge(
            ['base_fee' => 3000],
            $this->remoteArea(3000),
        )));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CUSTOMER, [
            'country_code' => 'KR',
            'zipcode' => '06234',
        ]);

        $this->assertTrue($result->redeliveryChanged);
        $this->assertTrue($result->toArray()['redelivery_changed']);
    }

    public function test_unchanged_redelivery_is_not_recorded_as_changed(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, array_merge(
            ['base_fee' => 3000],
            $this->remoteArea(3000),
        )));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertFalse($result->redeliveryChanged);
    }

    public function test_redelivery_change_is_recorded_even_when_the_two_legs_cost_the_same(): void
    {
        $order = $this->makeOrder();
        // 두 우편번호가 같은 도서산간 구간이라 회수·재발송 추가액이 같다 —
        // 금액만으로는 "안 바꿈" 과 구분되지 않으므로 사실 자체를 남겨야 한다.
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, array_merge(
            ['base_fee' => 3000],
            $this->remoteArea(3000),
        )));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CUSTOMER, [
            'country_code' => 'KR',
            'zipcode' => '63500',
        ]);

        $this->assertSame([3000, 3000], [
            $result->groups[0]['extra_fee_legs']['collection'],
            $result->groups[0]['extra_fee_legs']['redelivery'],
        ]);
        $this->assertTrue($result->redeliveryChanged);
    }

    public function test_return_never_records_a_redelivery_change(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::CUSTOMER, [
            'country_code' => 'KR',
            'zipcode' => '06234',
        ]);

        $this->assertFalse($result->redeliveryChanged);
    }

    public function test_seller_fault_still_records_the_redelivery_change(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '63001');

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER, [
            'country_code' => 'KR',
            'zipcode' => '06234',
        ]);

        $this->assertFalse($result->charged);
        $this->assertTrue($result->redeliveryChanged);
    }

    public function test_detail_only_address_edit_is_not_a_redelivery_change(): void
    {
        $order = $this->makeOrder();
        $policy = $this->makePolicy($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));
        $option = $this->makeOption($order, $policy);
        $this->withDeliveryAddress($order, '06234');

        // 우편번호·국가가 같으면 배송비가 달라지지 않으므로 산정 근거는 "변경 없음" 이다.
        // 고객이 상세주소를 손댔다는 사실은 신청서의 '고객이 수정함' 표시가 담당한다.
        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CUSTOMER, [
            'country_code' => 'KR',
            'zipcode' => '06234',
        ]);

        $this->assertFalse($result->redeliveryChanged);
    }

    // ================================================================
    // 19. 결과 3축 (통화 · 수량 안분 · 도서산간) — S15a2 승격분
    //
    // 이 셋은 S15a 까지 "결과에 얹히는 항" 이었다. 2026-09-02 확정으로 곱의 축이 되면서
    // 배송비 매니페스트가 24셀에서 288셀이 됐고, 그중 96셀 — 고정액 모드 전 조합(72) + 원 정책
    // × 무료 × 고객 귀책(24) — 이 이 세션의 몫이다. 나머지 192셀은 매니페스트의 이월 exclusion
    // 이 담당 세션(S15a3 · S15a4)을 적어 두고 있다.
    //
    // 세 축은 서로 다른 곳에서 값을 얻는다: 통화는 주문 스냅샷, 안분은 그룹에 담긴 대상 수,
    // 도서산간은 배송지 우편번호다. 그래서 픽스처도 그 셋만 갈아 끼운다 —
    // resultAxisScenario() 가 도서산간 설정은 언제나 켜 두고 우편번호만 바꾸는 이유가 이것이다.
    // ================================================================

    /**
     * 결과 3축(통화 · 수량 안분 · 도서산간)을 지정한 픽스처를 만듭니다.
     *
     * 도서산간 설정(`extra_fee_enabled`)은 세 값 모두에서 켜 둔다 — 축을 가르는 것은 배송지
     * 우편번호이지 설정이 아니다. 설정을 껐다 켜면 "구간 밖이라 0원" 과 "기능이 꺼져 0원" 이
     * 한 칸에서 뭉개져, 구간 판정이 죽어도 그 행이 계속 초록으로 남는다.
     *
     * @param  array  $country  국가별 설정 override (클레임 약관 · 부과정책)
     * @param  string  $currency  `order_snapshot` | `no_snapshot`
     * @param  string  $apportionment  `single_item` | `multi_item`
     * @param  string  $remoteArea  `none` | `flat` | `multiplied`
     * @return array{0: Order, 1: array} 주문과 클레임 대상 아이템
     */
    protected function resultAxisScenario(
        array $country,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): array {
        $order = $this->makeOrder([
            'currency_snapshot' => $currency === 'order_snapshot' ? $this->currencySnapshot() : null,
        ]);

        $policy = $this->makePolicy(array_merge(
            $this->remoteArea(3000, '63000-63999', $remoteArea === 'multiplied'),
            $country,
        ));

        $this->withDeliveryAddress($order, $remoteArea === 'none' ? '11111' : '63001');

        if ($apportionment === 'single_item') {
            $option = $this->makeOption($order, $policy, ['quantity' => 2, 'subtotal_price' => 40000]);

            return [$order, $this->items($option, 2)];
        }

        // 1 : 3 금액비라 그룹액이 1/4 · 3/4 로 갈린다. 두 옵션이 같은 정책·같은 국가라
        // 한 그룹으로 묶이므로, 그룹액 자체는 대상 수에 비례해 늘지 않는다.
        $cheap = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 10000]);
        $pricey = $this->makeOption($order, $policy, ['quantity' => 1, 'subtotal_price' => 30000]);

        return [$order, [
            ['order_option_id' => $cheap->id, 'cancel_quantity' => 1],
            ['order_option_id' => $pricey->id, 'cancel_quantity' => 1],
        ]];
    }

    /**
     * 산정액이 통화·안분 두 축에 어떻게 실리는지 단언합니다.
     *
     * 환산 분모 1,000 · USD 환율 0.75 는 `currencySnapshot()` 이 정한 값이다.
     *
     * USD 는 `assertSame` 으로 비교한다 — 절사 설정이 `0.01` 이면 산정액도 그 단위의
     * 배수여야 하고, 값이 아니라 **표현**이 어긋나는 회귀는 오차 허용 단언으로는 잡히지
     * 않는다. 이 셀들에서 그 회귀가 실제로 있었다: `applyRounding()` 이 단위를 도로 곱하는
     * 마지막 단계에서 2,500원(= 1.875 USD)이 `1.8800000000000001` 로 떨어졌고, 그 값이
     * `mc_*` 컬럼과 응답의 raw 숫자에 그대로 실렸다. S15a2 의 몫(4,000·8,000·3,000·
     * 6,000·0원)은 우연히 표현까지 맞아떨어져 드러나지 않았다. 생산 지점을 고친 뒤로는
     * 두 경로가 같은 double 을 내므로, 이 단언이 그 잔차의 재발을 192셀에서 감시한다.
     *
     * @param  ClaimShippingFee  $result  산정 결과
     * @param  int  $expectedFee  기대 청구액 (주문 기본 통화)
     * @param  string  $currency  `order_snapshot` | `no_snapshot`
     * @param  string  $apportionment  `single_item` | `multi_item`
     */
    protected function assertResultAxes(
        ClaimShippingFee $result,
        int $expectedFee,
        string $currency,
        string $apportionment,
    ): void {
        $this->assertSame($expectedFee, $result->fee);

        if ($currency === 'order_snapshot') {
            $this->assertSame(['KRW', 'USD'], array_keys($result->mcFee));
            $this->assertSame($expectedFee, $result->mcFee['KRW']);
            $this->assertSame(round($expectedFee / 1000 * 0.75, 2), $result->mcFee['USD']);
        } else {
            $this->assertSame([], $result->mcFee);
        }

        $shares = array_column($result->groups[0]['items'], 'fee');

        $this->assertCount($apportionment === 'single_item' ? 1 : 2, $shares);
        $this->assertSame($expectedFee, array_sum($shares));

        if ($apportionment === 'multi_item') {
            // 금액 비례 1 : 3 — 잔여는 마지막 항목에 실린다.
            $this->assertSame((int) round($expectedFee * 0.25), $shares[0]);
        }
    }

    /**
     * 결과 3축 × 클레임 유형 2 × 귀책 목록의 곱을 데이터 행으로 만듭니다.
     *
     * @param  array<int, string>  $faults  귀책 목록
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> 데이터 행
     */
    protected static function resultAxisRows(array $faults): array
    {
        $rows = [];

        foreach (['return', 'exchange'] as $claimType) {
            foreach ($faults as $fault) {
                foreach (['order_snapshot', 'no_snapshot'] as $currency) {
                    foreach (['single_item', 'multi_item'] as $apportionment) {
                        foreach (['none', 'flat', 'multiplied'] as $remoteArea) {
                            $key = implode('/', [$claimType, $fault, $currency, $apportionment, $remoteArea]);
                            $rows[$key] = [$claimType, $fault, $currency, $apportionment, $remoteArea];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * 고정액 모드 × 고객 귀책의 결과 3축 24행.
     *
     * @return array<string, array> 데이터 행
     */
    public static function fixedModeCustomerAxisProvider(): array
    {
        return self::resultAxisRows(['customer']);
    }

    /**
     * 고정액 모드 × 비-고객 귀책 2종의 결과 3축 48행.
     *
     * @return array<string, array> 데이터 행
     */
    public static function fixedModeNonCustomerAxisProvider(): array
    {
        return self::resultAxisRows(['seller', 'carrier']);
    }

    /**
     * 원 정책 모드 × 무료 부과정책 × 고객 귀책의 결과 3축 24행.
     *
     * @return array<string, array> 데이터 행
     */
    public static function freePolicyCustomerAxisProvider(): array
    {
        return self::resultAxisRows(['customer']);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 고정액 모드는 결과 3축이 무엇이든 선언액을 그대로 청구한다.
     *
     * 고정액은 배송 구간이 아니라 "이 클레임의 배송비" 를 통째로 선언한 값이다. 그래서 도서산간을
     * 얹지 않고(구간 판정 자체를 건너뛴다), 그룹에 담긴 대상이 둘이어도 두 번 물리지 않는다 —
     * 대상 수에 비례하는 것은 안분된 몫이지 그룹액이 아니다. 통화는 그 위에서 표기만 더한다.
     *
     * @effects claim_shipping_fee_fixed_mode_uses_the_declared_amount,
     *          claim_shipping_fee_fixed_mode_does_not_double_for_an_exchange,
     *          claim_shipping_fee_fixed_mode_charges_once_per_policy_group,
     *          claim_shipping_fee_remote_area_is_skipped_in_fixed_mode,
     *          claim_shipping_fee_remote_area_multiply_is_inert_for_a_non_per_unit_policy,
     *          claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate,
     *          claim_shipping_fee_multi_currency_is_empty_without_a_snapshot,
     *          claim_shipping_fee_group_fee_is_apportioned_across_items,
     *          claim_shipping_fee_apportioned_shares_sum_to_the_group_fee
     */
    #[DataProvider('fixedModeCustomerAxisProvider')]
    public function test_fixed_mode_charges_the_declared_amount_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            ['return_fixed_fee' => 4000, 'exchange_fixed_fee' => 8000],
            $currency,
            $apportionment,
            $remoteArea,
        );

        $result = $this->calc(
            $order,
            ClaimTypeEnum::from($claimType),
            $items,
            ClaimReasonFaultTypeEnum::from($fault),
        );

        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
        $this->assertSame(0, $result->extraFee);
        $this->assertResultAxes(
            $result,
            $claimType === 'exchange' ? 8000 : 4000,
            $currency,
            $apportionment,
        );
    }

    // 이 DataProvider 가 함께 검증하는 48조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=return, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=fixed, charge_policy=not_read, claim_type=exchange, fault=carrier, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 고정액 모드의 판매자·배송사 귀책은 결과 3축이 무엇이든 0원이다.
     *
     * 귀책 게이트는 산정보다 먼저 발화하므로 도서산간이 붙을 자리도, 안분할 그룹액도 생기지
     * 않는다. 그런데 이 조합은 정책 자신이 0원인 칸과 겉보기 결과가 같아서, 0원만 보면 게이트가
     * 죽어도 초록으로 남는다. 그래서 같은 픽스처를 고객 귀책으로 한 번 더 태워 그 정책이 실제로
     * 금액을 만들 수 있었음을 대조군으로 함께 본다.
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault,
     *          claim_shipping_fee_records_declared_mode_even_when_not_charged,
     *          claim_shipping_fee_remote_area_is_skipped_in_fixed_mode
     */
    #[DataProvider('fixedModeNonCustomerAxisProvider')]
    public function test_fixed_mode_is_zero_for_a_non_customer_fault_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            ['return_fixed_fee' => 4000, 'exchange_fixed_fee' => 8000],
            $currency,
            $apportionment,
            $remoteArea,
        );

        $type = ClaimTypeEnum::from($claimType);

        $result = $this->calc($order, $type, $items, ClaimReasonFaultTypeEnum::from($fault));

        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);
        // 청구가 없어도 모드는 선언 그대로 기록된다 (헤더 컬럼이 NOT NULL).
        $this->assertSame(ClaimShippingFeeModeEnum::FIXED, $result->mode);
        $this->assertSame(0, $result->extraFee);
        $this->assertResultAxes($result, 0, $currency, $apportionment);

        // 대조군 — 같은 픽스처가 고객 귀책이면 선언액이 실제로 나온다.
        $control = $this->calc($order, $type, $items, ClaimReasonFaultTypeEnum::CUSTOMER);

        $this->assertSame($claimType === 'exchange' ? 8000 : 4000, $control->fee);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=free, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 무료 부과정책은 기본 배송비가 0이어도 도서산간 추가액은 구간마다 청구한다.
     *
     * 이 칸은 세 축을 나란히 놓기 전에는 보이지 않았다 — 무료 정책 테스트는 도서산간이 없는
     * 배송지를, 도서산간 테스트는 유료 정책을 쓰고 있었다. 실제로는 기본액과 추가액이 서로 다른
     * 계산이고 무료는 앞의 것만 0으로 만든다. 반품은 회수 1구간, 교환은 회수·재발송 2구간이므로
     * 같은 도서산간이 교환에서 두 번 붙는다. `extra_fee_multiply` 는 per_* 부과정책에서만
     * 곱해지므로 무료 정책에서는 켜 두어도 `flat` 과 같은 값이 나온다.
     *
     * @effects claim_shipping_fee_free_policy_still_charges_the_remote_area_surcharge,
     *          claim_shipping_fee_remote_area_fee_is_added_per_leg,
     *          claim_shipping_fee_remote_area_multiply_is_inert_for_a_non_per_unit_policy,
     *          claim_shipping_fee_original_policy_return_is_one_way,
     *          claim_shipping_fee_original_policy_exchange_is_round_trip
     */
    #[DataProvider('freePolicyCustomerAxisProvider')]
    public function test_free_policy_charges_only_the_remote_area_surcharge_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            $this->originalPolicy(ChargePolicyEnum::FREE, ['base_fee' => 0]),
            $currency,
            $apportionment,
            $remoteArea,
        );

        $result = $this->calc(
            $order,
            ClaimTypeEnum::from($claimType),
            $items,
            ClaimReasonFaultTypeEnum::from($fault),
        );

        $legs = $claimType === 'exchange' ? 2 : 1;
        $expected = $remoteArea === 'none' ? 0 : 3000 * $legs;

        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame(ClaimShippingFee::REASON_CHARGED, $result->reason);
        $this->assertSame($expected, $result->extraFee);
        // 기본 배송비분은 fee - extraFee 다. 무료 정책이므로 언제나 0이어야 한다.
        $this->assertSame(0, $result->fee - $result->extraFee);
        $this->assertResultAxes($result, $expected, $currency, $apportionment);
    }
    // ================================================================
    // 20. 결과 3축 × 원 정책 모드 — S15a3 승격분
    //
    // S15a2 가 고정액 모드 전 조합(72셀)과 원 정책 × 무료(24셀)를 채웠고, 이 세션은 그 다음
    // 몫인 96셀 — 원 정책 × {고정, 조건부무료 충족·미달} × 고객 귀책(72) + 원 정책 ×
    // 판매자 귀책(24) — 을 채운다. 매니페스트의 '이월(S15a3)' exclusion 두 줄이 지워졌고,
    // 남은 96셀(수량당·구간·API 정책과 배송사 귀책)은 S15a4 의 이월 exclusion 이 맡는다.
    //
    // 고정액 모드와 갈리는 지점이 여기서 처음 드러난다: 원 정책 모드는 편도액을 재산출하고
    // 교환에서 ×2 하며, 도서산간을 구간마다 실제로 얹는다. 그래서 이 96셀의 기대액은
    // "편도액 × 배수 + 구간별 추가액" 이고, S15a2 의 extraFee === 0 전제가 여기서는 성립하지
    // 않는다. extra_fee_multiply 는 여전히 per_* 정책에서만 곱해지므로
    // (ChargePolicyEnum::perUnitPolicies()) 고정·조건부무료에서는 flat 과 multiplied 가
    // 같은 값을 낸다 — 그 플래그가 곱을 일으키는 칸은 S15a4 의 per_quantity 가 연다.
    // ================================================================

    /**
     * 부과정책 픽스처의 국가별 설정을 원 정책 기준 모드로 바꿉니다.
     *
     * 부과정책 파라미터는 `chargePolicyFixture()` 단일 출처를 그대로 쓰고 클레임 약관만
     * 갈아 끼운다 — 정책값을 여기서 다시 적으면 기대액(`one_way`)과 갈릴 수 있다.
     *
     * @param  string  $key  부과정책 픽스처 키
     * @return array 국가별 설정 override
     */
    protected static function originalPolicyCountry(string $key): array
    {
        return array_merge(
            self::chargePolicyFixture($key)['country'],
            ['claim_fee_mode' => ClaimShippingFeeModeEnum::ORIGINAL_POLICY],
        );
    }

    /**
     * 원 정책 모드의 기대 청구액과 도서산간 합을 계산합니다.
     *
     * `편도액 × 배수 + 구간별 추가액` — 반품은 회수 1구간, 교환은 회수·재발송 2구간이다.
     * 도서산간 단가 3,000 원은 `resultAxisScenario()` 가 놓는 `remoteArea()` 의 값이다.
     *
     * @param  string  $policyKey  부과정책 픽스처 키
     * @param  string  $claimType  `return` | `exchange`
     * @param  string  $remoteArea  `none` | `flat` | `multiplied`
     * @return array{0: int, 1: int} 기대 청구액과 도서산간 합
     */
    protected static function originalPolicyExpectation(string $policyKey, string $claimType, string $remoteArea): array
    {
        $legs = $claimType === 'exchange' ? 2 : 1;
        $extraFee = $remoteArea === 'none' ? 0 : 3000 * $legs;

        return [self::chargePolicyFixture($policyKey)['one_way'] * $legs + $extraFee, $extraFee];
    }

    /**
     * 원 정책 모드 × 고객 귀책의 결과 3축 24행.
     *
     * @return array<string, array> 데이터 행
     */
    public static function originalPolicyCustomerAxisProvider(): array
    {
        return self::resultAxisRows(['customer']);
    }

    /**
     * 원 정책 모드 × 판매자 귀책의 결과 3축 24행.
     *
     * @return array<string, array> 데이터 행
     */
    public static function originalPolicySellerAxisProvider(): array
    {
        return self::resultAxisRows(['seller']);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 고정 부과정책은 결과 3축이 무엇이든 편도액을 배수만큼 물리고 도서산간을 구간마다 얹는다.
     *
     * 같은 정책 행을 고정액 모드가 읽었을 때(S15a2)와 정반대다 — 그쪽은 배수도 도서산간도 없다.
     * 두 모드를 같은 결과 3축 위에 나란히 놓아야 "정책을 안 읽는 것" 과 "읽고 다르게 쓰는 것" 이
     * 구분된다. 부과정책 근거를 함께 단언하는 이유도 같다 — 모드가 조용히 고정액으로 떨어지면
     * 금액만 보고는 알 수 없다.
     *
     * @param  string  $claimType  클레임 유형
     * @param  string  $fault  귀책
     * @param  string  $currency  통화 스냅샷 유무
     * @param  string  $apportionment  그룹 대상 수
     * @param  string  $remoteArea  도서산간 구간
     *
     * @effects claim_shipping_fee_original_policy_return_is_one_way,
     *          claim_shipping_fee_original_policy_exchange_is_round_trip,
     *          claim_shipping_fee_remote_area_fee_is_added_per_leg,
     *          claim_shipping_fee_remote_area_multiply_is_inert_for_a_non_per_unit_policy,
     *          claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate,
     *          claim_shipping_fee_multi_currency_is_empty_without_a_snapshot,
     *          claim_shipping_fee_group_fee_is_apportioned_across_items,
     *          claim_shipping_fee_apportioned_shares_sum_to_the_group_fee
     */
    #[DataProvider('originalPolicyCustomerAxisProvider')]
    public function test_original_policy_fixed_charges_the_base_fee_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            self::originalPolicyCountry('fixed'),
            $currency,
            $apportionment,
            $remoteArea,
        );

        $result = $this->calc(
            $order,
            ClaimTypeEnum::from($claimType),
            $items,
            ClaimReasonFaultTypeEnum::from($fault),
        );

        [$expected, $extraFee] = self::originalPolicyExpectation('fixed', $claimType, $remoteArea);

        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame(ClaimShippingFee::REASON_CHARGED, $result->reason);
        $this->assertSame('fixed', $result->groups[0]['charge_policy']);
        $this->assertSame($extraFee, $result->extraFee);
        $this->assertResultAxes($result, $expected, $currency, $apportionment);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_unmet, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 조건부무료 미달은 기본 배송비를 그대로 물리고 도서산간을 그 위에 얹는다.
     *
     * 임계 500,000 원에 대해 그룹 금액은 40,000 원이라 미달이다 — 안분 축이 갈라도 그룹 합계는
     * 같으므로(단품 40,000 = 10,000 + 30,000) 임계 판정은 두 값에서 같아야 한다. 그 사실 자체가
     * 이 24셀의 산출물이다.
     *
     * @param  string  $claimType  클레임 유형
     * @param  string  $fault  귀책
     * @param  string  $currency  통화 스냅샷 유무
     * @param  string  $apportionment  그룹 대상 수
     * @param  string  $remoteArea  도서산간 구간
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge,
     *          claim_shipping_fee_original_policy_return_is_one_way,
     *          claim_shipping_fee_original_policy_exchange_is_round_trip,
     *          claim_shipping_fee_remote_area_fee_is_added_per_leg,
     *          claim_shipping_fee_remote_area_multiply_is_inert_for_a_non_per_unit_policy,
     *          claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate,
     *          claim_shipping_fee_multi_currency_is_empty_without_a_snapshot,
     *          claim_shipping_fee_group_fee_is_apportioned_across_items,
     *          claim_shipping_fee_apportioned_shares_sum_to_the_group_fee
     */
    #[DataProvider('originalPolicyCustomerAxisProvider')]
    public function test_original_policy_conditional_free_unmet_charges_the_base_fee_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            self::originalPolicyCountry('conditional_free_unmet'),
            $currency,
            $apportionment,
            $remoteArea,
        );

        $result = $this->calc(
            $order,
            ClaimTypeEnum::from($claimType),
            $items,
            ClaimReasonFaultTypeEnum::from($fault),
        );

        [$expected, $extraFee] = self::originalPolicyExpectation('conditional_free_unmet', $claimType, $remoteArea);

        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame('conditional_free', $result->groups[0]['charge_policy']);
        $this->assertSame($extraFee, $result->extraFee);
        // 기본 배송비분은 fee - extraFee 다. 임계 미달이므로 편도 2,500 × 배수여야 한다.
        $this->assertSame(2500 * ($claimType === 'exchange' ? 2 : 1), $result->fee - $result->extraFee);
        $this->assertResultAxes($result, $expected, $currency, $apportionment);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=return, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=conditional_free_met, claim_type=exchange, fault=customer, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 조건부무료 충족은 기본 배송비를 0으로 만들지만 도서산간 추가액은 구간마다 남는다.
     *
     * 무료 부과정책(S15a2)과 결과가 같아 보이지만 0원이 되는 경로가 다르다 — 이쪽은 같은
     * `base_fee` 2,500 이 임계 판정으로 지워진 것이고, 바로 위 미달 24셀이 같은 픽스처에서
     * 2,500 을 낸다. 두 24셀이 짝이라 임계 판정이 죽으면 한쪽이 반드시 깨진다.
     *
     * @param  string  $claimType  클레임 유형
     * @param  string  $fault  귀책
     * @param  string  $currency  통화 스냅샷 유무
     * @param  string  $apportionment  그룹 대상 수
     * @param  string  $remoteArea  도서산간 구간
     *
     * @effects claim_shipping_fee_conditional_free_threshold_decides_the_charge,
     *          claim_shipping_fee_free_policy_still_charges_the_remote_area_surcharge,
     *          claim_shipping_fee_remote_area_fee_is_added_per_leg,
     *          claim_shipping_fee_remote_area_multiply_is_inert_for_a_non_per_unit_policy,
     *          claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate,
     *          claim_shipping_fee_multi_currency_is_empty_without_a_snapshot,
     *          claim_shipping_fee_group_fee_is_apportioned_across_items,
     *          claim_shipping_fee_apportioned_shares_sum_to_the_group_fee
     */
    #[DataProvider('originalPolicyCustomerAxisProvider')]
    public function test_original_policy_conditional_free_met_charges_only_the_remote_area_surcharge_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            self::originalPolicyCountry('conditional_free_met'),
            $currency,
            $apportionment,
            $remoteArea,
        );

        $result = $this->calc(
            $order,
            ClaimTypeEnum::from($claimType),
            $items,
            ClaimReasonFaultTypeEnum::from($fault),
        );

        [$expected, $extraFee] = self::originalPolicyExpectation('conditional_free_met', $claimType, $remoteArea);

        $this->assertTrue($result->charged);
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame('conditional_free', $result->groups[0]['charge_policy']);
        $this->assertSame($extraFee, $result->extraFee);
        // 임계 충족이므로 기본 배송비분은 0이고 남는 것은 도서산간뿐이다.
        $this->assertSame(0, $result->fee - $result->extraFee);
        $this->assertResultAxes($result, $expected, $currency, $apportionment);
    }

    // 이 DataProvider 가 함께 검증하는 24조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=return, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=order_snapshot, apportionment=multi_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=single_item, remote_area=multiplied
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=none
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=flat
    // @scenario fee_mode=original_policy, charge_policy=fixed, claim_type=exchange, fault=seller, currency=no_snapshot, apportionment=multi_item, remote_area=multiplied

    /**
     * 원 정책 모드의 판매자 귀책은 결과 3축이 무엇이든 0원이고 도서산간도 붙지 않는다.
     *
     * 귀책 게이트가 산정보다 먼저 발화하므로 편도액도 구간별 추가액도 생기지 않는다. 그런데
     * 0원은 정책 자신이 0원인 칸(무료 · 조건부무료 충족)과 겉보기가 같아서, 0원만 단언하면
     * 게이트가 죽어도 이 24행이 계속 초록이다. 그래서 같은 픽스처를 고객 귀책으로 한 번 더
     * 태워 "편도액 × 배수 + 도서산간" 이 실제로 나왔음을 대조군으로 함께 본다 — 청구가 없어도
     * 모드와 부과정책은 근거에 선언 그대로 남는다.
     *
     * @param  string  $claimType  클레임 유형
     * @param  string  $fault  귀책
     * @param  string  $currency  통화 스냅샷 유무
     * @param  string  $apportionment  그룹 대상 수
     * @param  string  $remoteArea  도서산간 구간
     *
     * @effects claim_shipping_fee_zero_for_non_customer_fault,
     *          claim_shipping_fee_records_declared_mode_even_when_not_charged,
     *          claim_shipping_fee_multi_currency_uses_the_order_snapshot_rate,
     *          claim_shipping_fee_multi_currency_is_empty_without_a_snapshot
     */
    #[DataProvider('originalPolicySellerAxisProvider')]
    public function test_original_policy_is_zero_for_a_seller_fault_across_the_result_axes(
        string $claimType,
        string $fault,
        string $currency,
        string $apportionment,
        string $remoteArea,
    ): void {
        [$order, $items] = $this->resultAxisScenario(
            self::originalPolicyCountry('fixed'),
            $currency,
            $apportionment,
            $remoteArea,
        );

        $type = ClaimTypeEnum::from($claimType);

        $result = $this->calc($order, $type, $items, ClaimReasonFaultTypeEnum::from($fault));

        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);
        // 청구가 없어도 모드·부과정책은 선언 그대로 기록된다 (헤더 컬럼이 NOT NULL).
        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame('fixed', $result->groups[0]['charge_policy']);
        $this->assertSame(0, $result->extraFee);
        $this->assertResultAxes($result, 0, $currency, $apportionment);

        // 대조군 — 같은 픽스처가 고객 귀책이면 편도액 × 배수 + 도서산간이 실제로 나온다.
        [$expected] = self::originalPolicyExpectation('fixed', $claimType, $remoteArea);
        $control = $this->calc($order, $type, $items, ClaimReasonFaultTypeEnum::CUSTOMER);

        $this->assertSame($expected, $control->fee);
    }
}
