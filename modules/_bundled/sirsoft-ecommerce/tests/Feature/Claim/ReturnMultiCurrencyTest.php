<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claim;

use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 반품 흐름의 다통화 계약 — 결제 통화 ≠ 기준 통화 (#599 세션2 다통화 축)
 *
 * KRW 단일 통화 주문만으로는 **어느 값을 읽든 통과한다** — 기준 통화 금액과 결제 통화 금액이
 * 같은 숫자이기 때문이다. 픽스처 `foreignPaidClaimOrder(rate: 0.001, paidLocal: 50)` 은
 * 결제 USD **50** ↔ 기준 KRW **50,000** 으로 두 값을 갈라, 단위 혼용을 드러낸다.
 *
 * **반품에만 있는 축** — 이 스위트가 교환(`ExchangeMultiCurrencyTest`)과 갈라지는 지점이다:
 *
 *   | 문서 | 자기 `currency_code` | 통화의 출처 |
 *   | --- | --- | --- |
 *   | 반품(`ecommerce_order_returns`) | **있다** | `resolveBaseCurrency()` = **기준 통화** |
 *   | 환불(`ecommerce_order_refunds`) | **없다**(컬럼 자체가 없다) | 주문 스냅샷이 소유 |
 *
 * 반품 신청서는 **자기 통화를 박제**한다(`OrderReturnService:349`). 그 값은 결제 통화(USD)가
 * 아니라 **기준 통화(KRW)** 다 — 회수 배송비·청구액이 기준 통화로 산정되기 때문이다.
 * 그리고 반품 완료가 환불 신청서를 낳을 때(`completeReturn(createRefundRequest: true)`)
 * **그 통화는 함께 넘어가지 않는다** — 환불에는 담을 칸이 없다. 이 이관 지점에서
 * 금액이 결제 통화 단위로 새면 고객이 1,000분의 1 만 돌려받는다.
 *
 * 조합은 교환 축과 같은 네 가지를 덮는다(요구사항 2026-09-15 — 「KRW 에서 주행했던 시나리오와
 * 동일한 수준으로 다 커버」):
 *   ⓐ 기본 KRW · 결제 KRW    — 대조군. 다통화 단언이 「통화가 달라서」가 아니라
 *                              「계약이 지켜져서」 통과함을 보인다.
 *   ⓑ 기본 KRW · 표시 비KRW  — 보조 통화 맵과 서식.
 *   ⓒ 결제 ≠ 기준 (cross)    — 해외 결제. 단위 혼용이 드러나는 조합.
 *   ⓓ 상점 기본 통화가 비KRW — 원화를 하드코딩한 자리가 드러나고, 과거 주문의 표기 통화가
 *                              상점 설정 변경에 **불변**인지 잰다.
 *
 * #599 통합실측 세션2 브라우저 실측(주문 754 · KRW 단일)이 남긴 값:
 *   returns.currency_code          = 'KRW'
 *   returns.mc_claim_shipping_fee  = {KRW:3000, USD:3, JPY:345, CNY:17.4, EUR:2.34}
 *   returns.mc_total_charge_amount = 동일 (접수 시점 스냅샷)
 *
 * 그 실측은 **기준=결제=KRW** 라 「기준을 박았는지 결제를 박았는지」를 가르지 못한다.
 * 이 스위트가 그 구분을 담당한다. 실 해외결제는 처리할 수 없어 시뮬레이션으로 대체한다.
 *
 * 소유 시나리오: `return-multi-currency` (축 매핑은 각 테스트 메서드에 있다)
 */
class ReturnMultiCurrencyTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    /**
     * 통화 설정 캐시를 비운 뒤 기본 KRW + 보조 통화(USD·JPY) 설정과 클레임 사유를 준비합니다.
     *
     * 이전 통화 설정이 캐시에 남으면 바꾼 설정이 반영되지 않아, 아무것도 바뀌지 않은 채 테스트가 통과한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 이전 통화 설정이 캐시에 남으면 바꾼 것이 반영되지 않는다 —
        // 그러면 테스트는 통과하는데 실제로는 아무것도 안 바뀐 상태가 된다.
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        $this->setupTestCurrencySettings($this->multiCurrencyList());
        $this->seedClaimReasons();
    }

    /**
     * 테스트 통화 설정과 통화 설정 캐시를 지워 다음 테스트에 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        parent::tearDown();
    }

    /**
     * 기본 KRW + 보조 통화 두 종의 상점 설정입니다. (조합 ⓑ 의 전제)
     *
     * 보조 통화가 없으면 `mc_*` 맵이 KRW 한 줄뿐이라, 「기준 줄과 보조 줄이 어긋난다」는
     * 결함 형태가 구조적으로 생길 수 없다 — 그 상태의 통과는 아무것도 증명하지 못한다.
     *
     * @return array<int, array<string, mixed>> 통화 목록
     */
    private function multiCurrencyList(): array
    {
        return [
            [
                'code' => 'KRW',
                'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                'symbol' => '₩',
                'exchange_rate' => null,
                'decimal_places' => 0,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'is_default' => true,
            ],
            [
                'code' => 'USD',
                'name' => ['ko' => 'USD (달러)', 'en' => 'USD'],
                'symbol' => '$',
                'exchange_rate' => 0.001,
                'decimal_places' => 2,
                'rounding_unit' => '0.01',
                'rounding_method' => 'floor',
                'is_default' => false,
            ],
            [
                'code' => 'JPY',
                'name' => ['ko' => 'JPY (엔)', 'en' => 'JPY'],
                'symbol' => '¥',
                'exchange_rate' => 0.115,
                'decimal_places' => 0,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'is_default' => false,
            ],
        ];
    }

    /**
     * 결제 통화가 USD 인 주문에 반품을 접수합니다. (조합 ⓒ)
     *
     * @param  ClaimChargeMethodEnum  $chargeMethod  회수 배송비 청구 방식
     * @return array{0: Order, 1: OrderReturn} 새로 읽은 주문과 접수된 반품 신청서
     */
    private function foreignReturn(
        ClaimChargeMethodEnum $chargeMethod = ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
    ): array {
        $order = $this->foreignPaidClaimOrder(rate: 0.001, paidLocal: 50);

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'size_mismatch',
            'charge_method' => $chargeMethod,
        ]);

        return [$order->fresh(['options', 'payments']), $return->fresh(['returnOptions', 'order'])];
    }

    /**
     * 반품 신청서의 `currency_code` 는 **기준 통화**다 — 결제 통화가 아니다. (조합 ⓒ)
     *
     * 주문은 USD 로 결제됐지만(50 USD = 50,000 KRW) 회수 배송비·청구액은 기준 통화로
     * 산정되므로 신청서가 박제하는 통화도 기준 통화여야 한다. 결제 통화(USD)를 박아 두면
     * 이후 정산이 그 단위로 읽혀 1,000배 어긋나는데, 화면에는 **그럴듯한 숫자**가 뜬다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_snapshot_records_the_base_currency_not_the_payment_currency
     *
     * @return void 반환값 없음
     */
    public function test_return_snapshot_records_the_base_currency(): void
    {
        [$order, $return] = $this->foreignReturn();

        $this->assertSame(
            'USD',
            $order->currency_snapshot['order_currency'] ?? null,
            '픽스처 전제가 깨졌습니다 — 이 주문은 USD 로 결제됐어야 합니다.'
        );

        $this->assertSame(
            'KRW',
            $return->currency_code,
            '반품 신청서가 결제 통화(USD)를 박제했습니다. 회수 배송비·청구액은 기준 통화(KRW)로 '
                .'산정되므로, 통화만 결제 통화면 이후 정산이 그 단위로 읽혀 어긋납니다.'
        );
    }

    /**
     * 회수 배송비·청구액은 **기준 통화 단위**로 산정된다. (조합 ⓒ)
     *
     * 결제 통화(USD)로 세면 배송비가 3,000 이 아니라 3 으로 잡힌다 — 0 이 아니라
     * **그럴듯한 숫자**라 화면만 봐서는 드러나지 않는다. 이 단언은 값이 결제 통화 스케일로
     * 떨어지는 것을 막는다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_amounts_are_computed_in_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_return_amounts_are_computed_in_the_base_currency(): void
    {
        [$order, $return] = $this->foreignReturn();

        $paidLocal = (float) $order->payments->first()->paid_amount_local;
        $this->assertEqualsWithDelta(50, $paidLocal, 0.001, '픽스처 전제 — 결제 통화 실청구액은 50 USD 입니다.');

        $charge = (float) $return->total_charge_amount;

        if ($charge <= 0.0) {
            // 회수비가 붙지 않는 정책이면 이 축은 성립하지 않는다 — 조용히 통과시키지 않고 명시한다.
            $this->assertSame(0.0, (float) $return->claim_shipping_fee);

            return;
        }

        // 결제 통화 스케일(≈3)로 떨어졌는지 본다. 기준 통화 스케일이면 1,000배 크다.
        $this->assertGreaterThan(
            $paidLocal,
            $charge,
            '청구액이 결제 통화(USD) 스케일로 산정됐습니다 — 기준 통화(KRW)로 세면 1,000배 큰 값이어야 합니다.'
        );
    }

    /**
     * 보조 통화 맵은 기준 통화 금액을 환산한 것이다. (조합 ⓑ)
     *
     * `mc_claim_shipping_fee` 의 KRW 값이 `claim_shipping_fee`(기준 통화 금액)와 같아야 한다.
     * 한쪽만 맞고 다른 쪽이 낡아도 **둘 다 정상 서식**이라 화면에 흔적이 남지 않는다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_secondary_currency_map_matches_the_base_amount
     *
     * @return void 반환값 없음
     */
    public function test_secondary_currency_map_is_derived_from_the_base_amount(): void
    {
        [, $return] = $this->foreignReturn();

        $mcFee = $return->mc_claim_shipping_fee;

        if (empty($mcFee)) {
            $this->assertSame(
                0.0,
                (float) $return->claim_shipping_fee,
                '회수 배송비가 0 이 아닌데 보조 통화 맵이 비어 있습니다.'
            );

            return;
        }

        $this->assertArrayHasKey('KRW', $mcFee, '보조 통화 맵에 기준 통화(KRW)가 없습니다.');

        $this->assertEqualsWithDelta(
            (float) $return->claim_shipping_fee,
            (float) ($mcFee['KRW']['amount'] ?? $mcFee['KRW']),
            0.01,
            '보조 통화 맵의 KRW 값이 기준 금액과 다릅니다 — 한쪽이 낡았습니다.'
        );
    }

    /**
     * 응답의 `mc_*` 는 `{amount, formatted}` 객체여야 한다. (조합 ⓑ · 표기 축)
     *
     * 맨숫자면 화면이 `[1]?.formatted ?? [1]` 로 폴백해 **기호도 자릿수도 없는 숫자**를 그린다.
     * 응답에도 화면에도 오류가 남지 않아 조용히 지나간다(세션3 S3-D14 가 그 형태였다).
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_response_secondary_currency_is_formatted
     *
     * @return void 반환값 없음
     */
    public function test_resource_wraps_multi_currency_values_with_formatted_strings(): void
    {
        [, $return] = $this->foreignReturn();

        $payload = (new ClaimResource($return))
            ->withReasonLabels([])
            ->toArray(request());

        $mcKeys = array_filter(
            array_keys($payload),
            static fn (string $key): bool => str_starts_with($key, 'mc_')
        );

        $this->assertNotEmpty($mcKeys, 'ClaimResource 응답에 mc_* 키가 하나도 없습니다.');

        $inspected = 0;

        foreach ($mcKeys as $key) {
            $value = $payload[$key];

            if (! is_array($value) || $value === []) {
                continue;
            }

            foreach ($value as $currency => $entry) {
                $inspected++;

                $this->assertIsArray(
                    $entry,
                    "{$key}['{$currency}'] 가 맨숫자입니다. 화면이 .formatted 로 폴백해 "
                        .'통화 기호도 자릿수도 없는 숫자를 그립니다.'
                );

                $this->assertArrayHasKey('formatted', $entry, "{$key}['{$currency}'] 에 formatted 가 없습니다.");
                $this->assertArrayHasKey('amount', $entry, "{$key}['{$currency}'] 에 amount 가 없습니다.");
            }
        }

        $this->assertGreaterThan(
            0,
            $inspected,
            'mc_* 키는 있는데 전부 비어 있어 서식을 재지 못했습니다 — 이 통과는 근거가 없습니다.'
        );
    }

    /**
     * 결제 통화가 달라도 반품 전이가 완료까지 관통한다. (조합 ⓒ · 관통 축)
     *
     * 금액이 맞아도 전이가 통화 검증에 걸려 멈추면 반품이 끝나지 않는다. 금액 단언만으로는
     * 이 형태를 잡을 수 없다 — 상태 기계를 실제로 돌려 봐야 한다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_transitions_complete_under_cross_currency_payment
     *
     * @return void 반환값 없음
     */
    public function test_return_transitions_complete_under_cross_currency_payment(): void
    {
        [, $return] = $this->foreignReturn();

        $service = app(OrderReturnService::class);

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $target) {
            $return = $service->transition($return, $target, $this->claimTrackingFor($target));
        }

        $this->assertSame(
            ReturnStatusEnum::COLLECTED,
            $return->return_status,
            '결제 통화가 기준 통화와 다르면 반품 전이가 회수도착에서 멈춥니다.'
        );

        // 관통 뒤에도 박제된 통화는 그대로다 — 전이 과정에서 통화가 바뀌지 않는다.
        $this->assertSame('KRW', $return->fresh()->currency_code);
    }

    /**
     * 반품 완료가 낳는 환불 신청서의 금액도 **기준 통화**로 산정된다. (조합 ⓒ · 이관 축)
     *
     * `ecommerce_order_refunds` 에는 `currency_code` 컬럼 자체가 없다 — 반품이 들고 있던
     * 통화는 이 이관 지점에서 **함께 넘어가지 않고 사라진다**. 그래서 환불 금액이 결제 통화
     * 단위로 새면 그 사실을 가리킬 통화 값조차 남지 않는다. 고객은 50,000원 대신 50원을
     * 돌려받고, 화면에는 오류가 없다.
     *
     * @scenario base_currency=krw, payment_currency=cross
     *
     * @effects return_sourced_refund_is_computed_in_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_return_sourced_refund_is_computed_in_the_base_currency(): void
    {
        [$order, $return] = $this->foreignReturn();

        $service = app(OrderReturnService::class);

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $target) {
            $return = $service->transition($return, $target, $this->claimTrackingFor($target));
        }

        // 완료 처리가 환불신청서를 함께 접수하게 한다 — 운영자가 모달에서 고르는 실제 경로다
        // (`ClaimCompletionOptions` 는 기본값을 두지 않는다 — D26 이 폐기한 숨은 자동 실행이라).
        $return->loadMissing('returnOptions');
        $itemIds = $return->returnOptions->pluck('id')->all();

        $return = $service->completeReturn(
            $return,
            $itemIds,
            new ClaimCompletionOptions(
                restoreStockByItem: array_fill_keys($itemIds, true),
                createRefundRequest: true,
            ),
        );

        $refund = OrderRefund::findOrFail($this->returnRefundId($return));

        $paidLocal = (float) $order->payments->first()->paid_amount_local;
        $paidBase = (float) $order->payments->first()->paid_amount_base;

        $this->assertGreaterThan(0.0, (float) $refund->refund_amount, '환불 금액이 0 입니다.');

        $this->assertGreaterThan(
            $paidLocal,
            (float) $refund->refund_amount,
            '환불 금액이 결제 통화(USD) 스케일입니다 — 고객이 1,000분의 1 만 돌려받습니다. '
                .'환불 문서에는 통화 칸이 없어 이 사실을 가리킬 값조차 남지 않습니다.'
        );

        $this->assertLessThanOrEqual(
            $paidBase,
            (float) $refund->refund_amount,
            '환불 금액이 기준 통화 실결제액을 넘었습니다.'
        );
    }

    /**
     * 상점 기본 통화가 KRW 가 아니어도 반품 금액과 표기가 성립한다. (조합 ⓓ)
     *
     * 지금까지의 축은 전부 **기준 통화가 KRW** 였다 — 그 상태에서는 표기 함수가 원화로 찍든
     * 설정을 읽든 결과가 같아, 기본 통화를 하드코딩한 자리가 드러나지 않는다.
     *
     * @scenario base_currency=non_krw, payment_currency=cross
     *
     * @effects return_amounts_hold_when_the_store_base_currency_is_not_krw
     *
     * @return void 반환값 없음
     */
    public function test_return_holds_when_the_store_base_currency_is_not_krw(): void
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

        [, $return] = $this->foreignReturn();

        $array = (new ClaimResource($return))
            ->withReasonLabels([])
            ->toArray(request());

        // 이 주문의 스냅샷 기준 통화는 여전히 KRW 다 — 주문 시점 통화는 상점 설정이 바뀌어도 불변이다.
        $this->assertSame(
            'KRW',
            $array['currency_code'],
            '과거 주문의 표기 통화가 오늘의 상점 기본 통화를 따라갔습니다 — 접수 시점 통화는 불변이어야 합니다.'
        );

        $mc = $array['mc_total_charge_amount'] ?? null;

        if (is_array($mc) && $mc !== []) {
            $first = reset($mc);
            $this->assertIsArray($first, '상점 통화를 바꾼 뒤 보조 통화 줄이 맨숫자로 떨어졌습니다.');
            $this->assertArrayHasKey('formatted', $first);
        }
    }

    /**
     * 상점 기본 통화를 바꿔도 **접수 시점 금액**이 재환산되지 않는다. (조합 ⓓ)
     *
     * 과거 신청서의 청구액이 오늘의 기본 통화로 다시 계산되면, 같은 신청서가 조회 시점마다
     * 다른 금액을 보인다 — 운영자와 구매자가 서로 다른 숫자를 읽는다.
     *
     * @scenario base_currency=non_krw, payment_currency=cross
     *
     * @effects return_frozen_amounts_survive_a_store_currency_change
     *
     * @return void 반환값 없음
     */
    public function test_return_frozen_amounts_survive_a_store_currency_change(): void
    {
        [, $return] = $this->foreignReturn();

        $before = (float) $return->total_charge_amount;
        $beforeMc = $return->mc_total_charge_amount;

        $this->setupTestCurrencySettings([
            [
                'code' => 'JPY',
                'name' => ['ko' => 'JPY (엔)', 'en' => 'JPY'],
                'symbol' => '¥',
                'exchange_rate' => null,
                'decimal_places' => 0,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'is_default' => true,
            ],
        ], defaultCurrency: 'JPY');
        HasMultiCurrencyPrices::clearCurrencySettingsCache();

        $after = $return->fresh();

        $this->assertEqualsWithDelta(
            $before,
            (float) $after->total_charge_amount,
            0.001,
            '상점 통화를 바꿨다고 접수된 청구액이 달라지면 안 됩니다.'
        );

        $this->assertSame(
            $beforeMc,
            $after->mc_total_charge_amount,
            '보조 통화 맵도 접수 시점 동결값입니다.'
        );
    }

    /**
     * 대조군 — 단일 통화(KRW) 반품에서도 같은 계약이 성립한다. (조합 ⓐ)
     *
     * 이 테스트 자체는 결함을 잡지 못한다. **다통화 단언들이 통과한 이유가 「통화가 달라서」가
     * 아니라 「계약이 지켜져서」임을 보이는 기준선**이다. 단일 통화에서 깨지면 위의 단언들도
     * 근거를 잃는다.
     *
     * @scenario base_currency=krw, payment_currency=same_as_base
     *
     * @effects return_amounts_are_computed_in_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_single_currency_return_holds_the_same_contract(): void
    {
        $order = $this->deliveredClaimOrder();

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        // 결제 통화 = 기준 통화 = KRW
        $this->assertSame('KRW', $order->payments->first()->currency);
        $this->assertSame('KRW', $return->currency_code);
    }
}
