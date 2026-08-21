<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ClaimShippingFeeService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 클레임 배송비 산정 서비스 테스트 (S2b1 — 본체)
 *
 * 검증 축:
 * - 청구 대상 유형 게이트 (환불·취소는 회수 배송이 없어 청구 없음)
 * - 귀책 3종 (판매자·배송사 즉시 0원 / 고객 산정)
 * - `fixed` 모드 (반품 `return_fixed_fee` 편도 · 교환 `exchange_fixed_fee` 왕복 총액, 배수 미적용)
 * - `original_policy` 모드 × 부과정책 6종 (무료·고정·조건부무료 미달/충족·수량당·구간) × 반품 ×1 / 교환 ×2
 * - 다중 배송정책 그룹 합산과 헤더 모드 결정
 * - 대상 아이템 정규화와 정책 미해석 시 0원 처리
 *
 * 도서산간 재판정·다통화·수량 안분·원 정책 스냅샷 재구성은 S2b2 범위다.
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
     * @return Order 생성된 주문
     */
    protected function makeOrder(): Order
    {
        return Order::factory()->create([
            'order_status' => OrderStatusEnum::DELIVERED,
        ]);
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
                'delivery_policy_snapshot' => ['country_code' => $countryCode],
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
     * @return ClaimShippingFee 산정 결과
     */
    protected function calc(
        Order $order,
        ClaimTypeEnum $type,
        array $items,
        ClaimReasonFaultTypeEnum $fault = ClaimReasonFaultTypeEnum::CUSTOMER,
    ): ClaimShippingFee {
        return $this->service->calculate($order->fresh(), $type, $items, $fault);
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

    public function test_refund_claim_type_is_not_charged(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::REFUND, $this->items($option));

        $this->assertSame(0, $result->fee);
        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_CLAIM_TYPE_NOT_CHARGEABLE, $result->reason);
    }

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

    public function test_return_with_seller_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
        $this->assertFalse($result->charged);
        $this->assertSame(ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER, $result->reason);
    }

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

    public function test_exchange_with_seller_fault_is_zero(): void
    {
        [$order, $option] = $this->scenario();

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(0, $result->fee);
    }

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

    public function test_seller_fault_still_records_the_declared_original_policy_mode(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option), ClaimReasonFaultTypeEnum::SELLER);

        $this->assertSame(ClaimShippingFeeModeEnum::ORIGINAL_POLICY, $result->mode);
        $this->assertSame(0, $result->fee);
    }

    public function test_carrier_fault_with_original_policy_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 9000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option), ClaimReasonFaultTypeEnum::CARRIER);

        $this->assertSame(0, $result->fee);
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

    public function test_fixed_mode_return_uses_return_fixed_fee(): void
    {
        [$order, $option] = $this->scenario(['return_fixed_fee' => 2500, 'exchange_fixed_fee' => 7000]);

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(2500, $result->fee);
    }

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

    public function test_original_policy_free_return_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FREE, ['base_fee' => 0]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_free_exchange_is_zero(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FREE, ['base_fee' => 0]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_fixed_return_is_one_way(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option));

        $this->assertSame(3000, $result->fee);
    }

    public function test_original_policy_fixed_exchange_is_round_trip(): void
    {
        [$order, $option] = $this->scenario($this->originalPolicy(ChargePolicyEnum::FIXED, ['base_fee' => 3000]));

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option));

        $this->assertSame(6000, $result->fee);
    }

    public function test_original_policy_conditional_free_unmet_return_charges_base_fee(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 50000]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 1));

        $this->assertSame(2500, $result->fee);
    }

    public function test_original_policy_conditional_free_unmet_exchange_doubles_base_fee(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 50000]),
            ['quantity' => 2, 'subtotal_price' => 20000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 1));

        $this->assertSame(5000, $result->fee);
    }

    public function test_original_policy_conditional_free_met_return_is_zero(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 10000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_conditional_free_met_exchange_is_zero(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::CONDITIONAL_FREE, ['base_fee' => 2500, 'free_threshold' => 10000]),
            ['quantity' => 2, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 2));

        $this->assertSame(0, $result->fee);
    }

    public function test_original_policy_per_quantity_return_scales_with_claim_quantity(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::RETURN, $this->items($option, 3));

        $this->assertSame(3000, $result->fee);
    }

    public function test_original_policy_per_quantity_exchange_doubles(): void
    {
        [$order, $option] = $this->scenario(
            $this->originalPolicy(ChargePolicyEnum::PER_QUANTITY, ['base_fee' => 1000, 'ranges' => ['unit_value' => 1]]),
            ['quantity' => 4, 'subtotal_price' => 40000],
        );

        $result = $this->calc($order, ClaimTypeEnum::EXCHANGE, $this->items($option, 3));

        $this->assertSame(6000, $result->fee);
    }

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
            ['fee', 'mode', 'claim_type', 'fault_type', 'charged', 'reason', 'groups'],
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
                'hook_overridden', 'base_fee', 'multiplier', 'fee', 'reason',
                'item_count', 'total_amount', 'total_quantity',
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
}
