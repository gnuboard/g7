<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claim;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 교환 흐름의 다통화 계약 — 결제 통화 ≠ 기준 통화 (#599 세션3 다통화 축)
 *
 * KRW 단일 통화 주문만으로는 **화면이 어느 값을 읽든 통과한다** — 기준 통화 금액과 결제 통화
 * 금액이 같은 숫자이기 때문이다. 그래서 단위를 혼용하는 오류가 드러나지 않는다.
 *
 * 이 스위트는 결제 통화가 USD 인 주문(`foreignPaidClaimOrder`)으로 교환을 관통시켜 두 축을 잰다:
 *  - **금액 축**: 차액·왕복 배송비·총 청구액이 기준 통화(KRW)로 산정되고, 보조 통화 맵이
 *    같은 값의 다른 표기인지. 한쪽만 맞고 다른 쪽이 낡아도 둘 다 정상 서식이라 흔적이 없다.
 *  - **표기 축**: 응답의 `mc_*` 가 `{amount, formatted}` 객체인지. 맨숫자면 화면이
 *    `[1]?.formatted ?? [1]` 로 폴백해 **기호도 자릿수도 없는 숫자**를 그린다(S3-D14 전례).
 *
 * 소유 시나리오: `exchange-multi-currency` (축 매핑은 각 테스트 메서드에 있다)
 *
 * 픽스처 `foreignPaidClaimOrder()` 는 있었지만 **교환 경로에서 한 번도 쓰이지 않았다** —
 * 환불 축(`ClaimRefundExecutionTest`)만 쓰고 있었다.
 */
class ExchangeMultiCurrencyTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정 캐시를 비우고 테스트용 다통화 설정·클레임 사유를 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 테스트용 다통화 설정을 지워 다음 테스트로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제 통화가 USD 인 주문에 고가 옵션 교환을 접수합니다.
     *
     * @param  int  $priceAdjustment  교환 대상 옵션의 가격 조정 (양수 = 고가 → 추가 청구)
     * @return array{0: Order, 1: OrderExchange} 새로 읽은 주문과 접수된 교환 신청서
     */
    private function foreignExchange(int $priceAdjustment = 10000): array
    {
        $order = $this->foreignPaidClaimOrder(rate: 0.001, paidLocal: 50);
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
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
        ]);

        return [$order->fresh(['options', 'shippings', 'payments']), $exchange->fresh(['exchangeOptions', 'order'])];
    }

    /**
     * 결제 통화가 달라도 교환 금액은 **기준 통화**로 산정된다.
     *
     * 주문은 USD 로 결제됐지만(50 USD) 차액·배송비·청구액은 기준 통화 KRW 로 센다 —
     * 결제 통화로 세면 50 과 50,000 이 뒤섞여 청구액이 1,000배 어긋난다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects exchange_amounts_are_computed_in_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_exchange_amounts_are_computed_in_the_base_currency(): void
    {
        [$order, $exchange] = $this->foreignExchange(priceAdjustment: 10000);

        // 주문은 USD 결제다 — 이 축이 성립하는지 먼저 확인한다.
        $this->assertSame('USD', $order->payments->first()->currency);
        $this->assertSame('KRW', $order->currency_snapshot['base_currency']);

        // 차액은 기준 통화(KRW)다. USD 로 셌다면 10 이 나온다.
        $this->assertEqualsWithDelta(10000, (float) $exchange->additional_product_amount, 0.001);
        $this->assertSame('KRW', $exchange->currency_code);

        // 왕복 배송비 6,000 + 차액 10,000 = 16,000 (기준 통화)
        $this->assertEqualsWithDelta(16000, (float) $exchange->total_charge_amount, 0.001);
    }

    /**
     * 보조 통화 맵은 기준 통화 줄과 **같은 값의 다른 표기**다.
     *
     * 되돌림 축: `mc_total_charge_amount` 가 결제 통화(USD 50)를 근거로 만들어지면 금액이 어긋난다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects exchange_secondary_currency_matches_the_base_line
     *
     * @return void 반환값 없음
     */
    public function test_exchange_secondary_currency_matches_the_base_line(): void
    {
        [, $exchange] = $this->foreignExchange(priceAdjustment: 10000);

        $mc = $exchange->mc_total_charge_amount;

        $this->assertIsArray($mc, '보조 통화 맵이 없으면 화면이 외화 줄을 세우지 못한다.');
        $this->assertArrayHasKey('KRW', $mc);

        // 보조 통화 맵의 기준 통화 항목은 기준 줄과 같아야 한다 — 다르면 한 칸에서 두 값이 갈린다.
        $this->assertEqualsWithDelta(
            (float) $exchange->total_charge_amount,
            (float) $mc['KRW'],
            0.001,
            '보조 통화 맵의 KRW 가 기준 줄과 다르면 같은 칸에서 두 금액이 갈린다.'
        );
    }

    /**
     * 응답의 보조 통화는 **서식을 입은 채** 내려간다. (S3-D14 와 같은 계약)
     *
     * 맨숫자로 내려가면 화면이 `[1]?.formatted ?? [1]` 로 폴백해 기호 없는 숫자를 그린다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects exchange_response_secondary_currency_is_formatted
     *
     * @return void 반환값 없음
     */
    public function test_exchange_response_secondary_currency_is_formatted(): void
    {
        [, $exchange] = $this->foreignExchange(priceAdjustment: 10000);

        $array = (new ClaimResource($exchange))
            ->withReasonLabels([])
            ->toArray(request());

        foreach (['mc_total_charge_amount', 'mc_claim_shipping_fee'] as $key) {
            $this->assertArrayHasKey($key, $array);

            if ($array[$key] === [] || $array[$key] === null) {
                continue;
            }

            $first = reset($array[$key]);
            $this->assertIsArray($first, $key.' 가 맨숫자면 화면이 기호 없는 숫자를 그린다.');
            $this->assertArrayHasKey('formatted', $first, $key.' 에 서식이 없다.');
            $this->assertArrayHasKey('amount', $first);
        }
    }

    /**
     * 결제 통화가 달라도 교환 관통(승인 → 재발송완료)이 끝까지 간다.
     *
     * 금액 축과 별개로 **상태 전이 자체가 통화에 걸려 멈추지 않는지**를 본다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects exchange_transitions_complete_under_cross_currency_payment
     *
     * @return void 반환값 없음
     */
    public function test_exchange_transitions_complete_under_cross_currency_payment(): void
    {
        [, $exchange] = $this->foreignExchange(priceAdjustment: 10000);
        $service = app(OrderExchangeService::class);

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

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->exchange_status);

        // 관통 뒤에도 금액은 기준 통화 그대로다 — 전이 과정에서 통화가 바뀌지 않는다.
        $this->assertEqualsWithDelta(16000, (float) $exchange->fresh()->total_charge_amount, 0.001);
    }

    /**
     * 기본 통화가 KRW 가 아닌 상점에서도 교환 금액과 표기가 성립한다. (조합 C)
     *
     * 지금까지의 축은 전부 **기준 통화가 KRW** 였다 — 그 상태에서는 `formatBaseCurrency()` 가
     * 원화로 찍든 설정을 읽든 결과가 같아, 기본 통화를 하드코딩한 자리가 드러나지 않는다.
     * 기본 통화를 USD 로 바꿔 두면 원화로 하드코딩된 표기가 `$` 대신 `원` 으로 새어 나온다.
     *
     * @scenario base_currency=non_krw, payment_currency=cross
     *
     * @effects exchange_amounts_hold_when_the_store_base_currency_is_not_krw
     *
     * @return void 반환값 없음
     */
    public function test_exchange_holds_when_the_store_base_currency_is_not_krw(): void
    {
        // 상점 기본 통화를 USD 로 바꾼다 — 테스트 전용 설정 경로라 개발 설정은 건드리지 않는다.
        $this->setupTestCurrencySettings([
            [
                'code' => 'USD',
                'name' => ['ko' => 'USD (달러)', 'en' => 'USD'],
                'symbol' => '$',
                'exchange_rate' => null,
                'decimal_places' => 2,
                'rounding_unit' => '0.01',
                'rounding_method' => 'floor',
                'is_default' => true,
            ],
            [
                'code' => 'KRW',
                'name' => ['ko' => 'KRW (원)', 'en' => 'KRW'],
                'symbol' => '₩',
                'exchange_rate' => 1000,
                'decimal_places' => 0,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'is_default' => false,
            ],
        ], defaultCurrency: 'USD');
        HasMultiCurrencyPrices::clearCurrencySettingsCache();

        [, $exchange] = $this->foreignExchange(priceAdjustment: 10000);

        $array = (new ClaimResource($exchange))
            ->withReasonLabels([])
            ->toArray(request());

        // 이 주문의 스냅샷 기준 통화는 여전히 KRW 다 — 주문 시점 통화는 상점 설정이 바뀌어도 불변이다.
        $this->assertSame('KRW', $array['currency_code'], '과거 주문의 표기 통화는 상점 설정 변경에 불변이어야 한다.');

        // 금액 자체도 접수 시점 그대로다.
        $this->assertEqualsWithDelta(16000, (float) $array['total_charge_amount'], 0.001);

        // 보조 통화 줄은 서식을 입은 채 남는다.
        $mc = $array['mc_total_charge_amount'];
        $this->assertIsArray($mc);
        $first = reset($mc);
        $this->assertArrayHasKey('formatted', $first);
    }

    /**
     * 상점 기본 통화를 바꿔도 **접수 시점 금액**이 재환산되지 않는다.
     *
     * 과거 주문의 청구액이 오늘의 기본 통화로 다시 계산되면, 같은 신청서가 조회 시점마다 다른
     * 금액을 보인다 — 운영자와 구매자가 서로 다른 숫자를 보게 된다.
     *
     * @scenario base_currency=non_krw, payment_currency=cross
     *
     * @effects exchange_frozen_amounts_survive_a_store_currency_change
     *
     * @return void 반환값 없음
     */
    public function test_exchange_frozen_amounts_survive_a_store_currency_change(): void
    {
        [, $exchange] = $this->foreignExchange(priceAdjustment: 10000);

        $before = (float) $exchange->total_charge_amount;
        $beforeMc = $exchange->mc_total_charge_amount;

        // 상점 기본 통화 변경
        $this->setupTestCurrencySettings([
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'symbol' => '¥', 'exchange_rate' => null,
                'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'is_default' => true],
        ], defaultCurrency: 'JPY');
        HasMultiCurrencyPrices::clearCurrencySettingsCache();

        $after = $exchange->fresh();

        $this->assertEqualsWithDelta($before, (float) $after->total_charge_amount, 0.001,
            '상점 통화를 바꿨다고 접수된 청구액이 달라지면 안 된다.');
        $this->assertSame($beforeMc, $after->mc_total_charge_amount,
            '보조 통화 맵도 접수 시점 동결값이다.');
    }

    /**
     * 대조군 — 단일 통화(KRW) 주문에서도 같은 계약이 성립한다.
     *
     * 이 테스트 자체는 결함을 잡지 못한다. **다통화 테스트가 통과한 이유가 「통화가 달라서」가
     * 아니라 「계약이 지켜져서」임을 보이는 기준선**이다. 단일 통화에서 깨지면 위의 다통화
     * 단언들도 근거를 잃는다.
     *
     * @scenario base_currency=krw, payment_currency=same_as_base
     *
     * @effects exchange_amounts_are_computed_in_the_base_currency
     * @effects exchange_secondary_currency_matches_the_base_line
     *
     * @return void 반환값 없음
     */
    public function test_single_currency_exchange_holds_the_same_contract(): void
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderExchangeService::class);

        $newOption = ProductOptionFactory::new()
            ->forProduct($order->options->first()->product)
            ->create([
                'price_adjustment' => 10000,
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
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
        ]);

        // 결제 통화 = 기준 통화 = KRW
        $this->assertSame('KRW', $order->payments->first()->currency);
        $this->assertSame('KRW', $exchange->currency_code);

        // 다통화 건과 **같은 금액**이 나온다 — 통화 조합이 금액을 바꾸지 않는다.
        $this->assertEqualsWithDelta(10000, (float) $exchange->additional_product_amount, 0.001);
        $this->assertEqualsWithDelta(16000, (float) $exchange->total_charge_amount, 0.001);

        $mc = $exchange->mc_total_charge_amount;
        $this->assertIsArray($mc);
        $this->assertEqualsWithDelta((float) $exchange->total_charge_amount, (float) $mc['KRW'], 0.001);
    }

    /**
     * 복합 경로 — 교환 완료가 만든 **환불신청서**도 기준 통화로 선다. (세션3 핵심 축)
     *
     * 저가 옵션 교환은 완료 시 차액만큼의 환불신청서를 자동 생성한다(복합 경로). 결제가 USD 인
     * 주문에서 이 환불액이 **결제 통화로 세어지면** 10,000 이 아니라 10 이 되어 고객이 1,000분의
     * 1 만 돌려받는다 — 금액이 0 이 아니라 **그럴듯한 숫자**라 화면만 보면 드러나지 않는다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects exchange_sourced_refund_is_computed_in_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_exchange_sourced_refund_is_computed_in_the_base_currency(): void
    {
        $order = $this->foreignPaidClaimOrder(rate: 0.001, paidLocal: 50);
        $service = app(OrderExchangeService::class);

        // 저가 옵션으로 교환 — 완료 시 차액 환불이 생긴다.
        $newOption = ProductOptionFactory::new()
            ->forProduct($order->options->first()->product)
            ->create([
                'price_adjustment' => -10000,
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

        $exchange = $service->completeExchange(
            $exchange,
            [],
            new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true),
        );

        $refund = OrderRefund::query()
            ->where('refund_source_type', RefundSourceTypeEnum::EXCHANGE->value)
            ->where('refund_source_id', $exchange->id)
            ->first();

        $this->assertNotNull($refund, '교환 완료가 차액 환불신청서를 만들어야 이 축을 잴 수 있다.');

        // 환불신청서에는 `currency_code` 컬럼이 없다 — 통화는 **주문 스냅샷**이 소유하고
        // 리소스가 거기서 해석한다(`ClaimResource:184`). 그러므로 금액 단위로 판정한다.
        $this->assertGreaterThan(
            100,
            (float) $refund->refund_amount,
            '환불액이 결제 통화 단위로 세어지면 1,000분의 1 이 되어 고객이 거의 못 돌려받는다.'
        );

        // 응답이 그리는 통화도 주문 기준 통화(KRW)여야 한다 — 결제 통화(USD)가 아니다.
        $array = (new ClaimResource($refund->fresh(['order'])))
            ->withReasonLabels([])
            ->toArray(request());

        $this->assertSame('KRW', $array['currency_code'], '환불 화면이 결제 통화로 그리면 단위가 어긋난다.');
    }
}
