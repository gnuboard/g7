<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 신청서 리소스 — 환불 금액의 보조 통화 계약 테스트
 *
 * 신청서 상세의 대비 행은 기준 통화 줄 바로 밑에 보조 통화 줄을 병기한다. 두 줄은 **같은 값의
 * 다른 표기**여야 하는데, 읽는 필드가 어긋나도 둘 다 정상 서식이라 응답에도 화면에도 흔적이
 * 남지 않는다(2026-09-01 3차 계획 검증).
 *
 * 이 스위트가 고정하는 두 축:
 *  - 환불 유형: 기준 줄이 `refund_total`(현금 + 마일리지)이므로 보조 통화도 **합계**여야 한다
 *  - 취소 유형: 보조 통화 키가 아예 없어 다통화 상점에서 그 줄이 통째로 사라졌다
 *
 * 소유 시나리오: `claim-resource-secondary-currency` (축 매핑은 각 테스트 메서드에 있다)
 */
class ClaimResourceRefundMultiCurrencyTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    /**
     * 리소스 배열로 변환합니다. (사유 라벨은 주입해 DB 조회를 태우지 않는다)
     *
     * @param  OrderRefund|OrderCancel  $claim  신청서
     * @return array<string, mixed> 응답 배열
     */
    private function toArray(OrderRefund|OrderCancel $claim): array
    {
        return (new ClaimResource($claim))
            ->withReasonLabels([])
            ->toArray(Request::create('/'));
    }

    /**
     * 환불 신청서의 보조 통화는 현금 + 마일리지 **합계**다.
     *
     * 되돌림 축: `mc_refund_total` 을 `mc_refund_amount` 로 되돌리면 마일리지분이 빠져 red.
     *
     * @scenario claim_type=refund, amount_source=stored_map, evidence=present
     *
     * @effects refund_secondary_currency_is_sum_of_cash_and_points
     */
    public function test_refund_claim_secondary_currency_is_the_sum_of_cash_and_points(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-TEST-1',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 30000,
            'refund_points_amount' => 5000,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            // 현금분과 마일리지분이 **다른 맵**이다 — 한쪽만 읽으면 화면의 두 줄이 갈린다.
            'mc_refund_amount' => ['KRW' => 30000, 'USD' => 22.5],
            'mc_refund_points_amount' => ['KRW' => 5000, 'USD' => 3.75],
        ]);

        $array = $this->toArray($refund);

        $this->assertArrayHasKey('mc_refund_total', $array, '합계 키가 없으면 화면이 현금분만 그린다.');
        $this->assertNotSame([], $array['mc_refund_total'], '합계가 비면 보조 통화 줄이 통째로 사라진다.');

        // 기준 통화 줄이 그리는 값과 같은 근거 위에 서야 한다.
        $this->assertEqualsWithDelta(35000, $array['refund_total'], 0.001, '기준 줄은 현금 + 마일리지다.');

        $this->assertSame(35000, $array['mc_refund_total']['KRW']['amount']);
        $this->assertEqualsWithDelta(26.25, $array['mc_refund_total']['USD']['amount'], 0.001);
        $this->assertArrayHasKey('formatted', $array['mc_refund_total']['USD']);

        // 현금분 단독 키는 그대로 남는다 — 이 키를 읽는 다른 소비처의 형태를 바꾸지 않는다.
        $this->assertSame(30000, $array['mc_refund_amount']['KRW']['amount']);
    }

    /**
     * 마일리지가 없는 환불에서도 합계 키가 서고 현금분과 같은 값이다.
     *
     * @scenario claim_type=refund, amount_source=stored_map, evidence=present
     *
     * @effects refund_sum_falls_back_to_cash_without_points
     */
    public function test_refund_claim_sum_falls_back_to_cash_when_there_are_no_points(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-TEST-2',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 12000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'mc_refund_amount' => ['KRW' => 12000, 'USD' => 9],
            'mc_refund_points_amount' => null,
        ]);

        $array = $this->toArray($refund);

        $this->assertSame(12000, $array['mc_refund_total']['KRW']['amount']);
        $this->assertEqualsWithDelta(9.0, $array['mc_refund_total']['USD']['amount'], 0.001);
    }

    /**
     * 통화 스냅샷이 없어 저장된 맵이 비면 합계도 빈 배열이다. (없는 값을 만들어 내지 않는다)
     *
     * @scenario claim_type=refund, amount_source=stored_map, evidence=absent
     *
     * @effects secondary_currency_is_empty_when_nothing_was_stored
     */
    public function test_refund_claim_sum_is_empty_when_nothing_was_stored(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-TEST-3',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 12000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'mc_refund_amount' => null,
            'mc_refund_points_amount' => null,
        ]);

        $this->assertSame([], $this->toArray($refund)['mc_refund_total']);
    }

    /**
     * 환불 신청서는 배송비 환불액의 보조 통화도 내보낸다.
     *
     * 상세의 배송비 대비 행은 교환·반품에서 `mc_claim_shipping_fee` 를 병기한다. 환불 분기의
     * 짝(`mc_refund_shipping_amount`)은 **컬럼이 이미 있는데 리소스가 노출하지 않아**, 같은
     * 자리에서 환불 신청서만 외화가 사라졌다(2026-09-01 4차 계획 검증).
     *
     * 되돌림 축: 이 키를 리소스에서 빼면 화면의 그 줄이 통째로 사라진다.
     *
     * @scenario claim_type=refund, amount_source=stored_map, evidence=present
     *
     * @effects refund_exposes_shipping_refund_secondary_currency
     */
    public function test_refund_claim_exposes_shipping_refund_secondary_currency(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-TEST-5',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 12000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 3000,
            'currency_code' => 'KRW',
            'mc_refund_amount' => ['KRW' => 12000, 'USD' => 9],
            // 환불액과 **다른 값**이다 — 배송비 줄이 환불액 맵을 읽으면 단언이 깨진다.
            'mc_refund_shipping_amount' => ['KRW' => 3000, 'USD' => 2.55],
        ]);

        $array = $this->toArray($refund);

        $this->assertArrayHasKey('mc_refund_shipping_amount', $array, '노출되지 않으면 화면이 그릴 재료가 없다.');
        $this->assertSame(3000, $array['mc_refund_shipping_amount']['KRW']['amount']);
        $this->assertEqualsWithDelta(2.55, $array['mc_refund_shipping_amount']['USD']['amount'], 0.001);
        $this->assertArrayHasKey('formatted', $array['mc_refund_shipping_amount']['USD']);

        // 기준 통화 줄이 그리는 값과 같은 근거 위에 서야 한다.
        $this->assertEqualsWithDelta(3000, $array['refund_shipping_amount'], 0.001);
    }

    /**
     * 저장된 맵이 없으면 빈 배열이다. (없는 값을 만들어 내지 않는다)
     *
     * @scenario claim_type=refund, amount_source=stored_map, evidence=absent
     *
     * @effects secondary_currency_is_empty_when_nothing_was_stored
     */
    public function test_refund_claim_shipping_secondary_currency_is_empty_when_nothing_was_stored(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-TEST-6',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 12000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'mc_refund_shipping_amount' => null,
        ]);

        $this->assertSame([], $this->toArray($refund)['mc_refund_shipping_amount']);
    }

    /**
     * 취소 신청서도 형제 유형과 같이 보조 통화를 병기한다.
     *
     * 되돌림 축: `mc_refund_amount` 키를 빼면 화면의 그 줄이 사라진다 — 취소만 외화가 없다.
     *
     * @scenario claim_type=cancel, amount_source=stored_map, evidence=present
     *
     * @effects cancel_carries_linked_refund_secondary_currency
     */
    public function test_cancel_claim_carries_the_linked_refunds_secondary_currency(): void
    {
        $cancel = OrderCancel::make([
            'cancel_number' => 'CN-TEST-1',
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'currency_code' => 'KRW',
        ]);

        $cancel->setRelation('refund', OrderRefund::make([
            'refund_number' => 'RF-TEST-4',
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_amount' => 18000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'mc_refund_amount' => ['KRW' => 18000, 'USD' => 13.5],
        ]));

        $array = $this->toArray($cancel);

        $this->assertArrayHasKey('mc_refund_amount', $array, '취소만 보조 통화 키가 없으면 그 유형의 외화 줄이 사라진다.');
        $this->assertSame(18000, $array['mc_refund_amount']['KRW']['amount']);
        $this->assertEqualsWithDelta(13.5, $array['mc_refund_amount']['USD']['amount'], 0.001);

        // 기준 줄은 현금분이므로 보조 통화도 현금분이다 — 합계를 실으면 두 줄이 갈린다.
        $this->assertEqualsWithDelta(18000, $array['refund_amount'], 0.001);
    }

    /**
     * 연결된 환불이 없는 취소는 보조 통화가 빈 배열이다. (키는 언제나 선다)
     *
     * @scenario claim_type=cancel, amount_source=stored_map, evidence=absent
     *
     * @effects secondary_currency_is_empty_when_nothing_was_stored
     */
    public function test_cancel_claim_without_a_refund_exposes_an_empty_secondary_currency(): void
    {
        $cancel = OrderCancel::make([
            'cancel_number' => 'CN-TEST-2',
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'currency_code' => 'KRW',
        ]);

        $array = $this->toArray($cancel);

        $this->assertArrayHasKey('mc_refund_amount', $array);
        $this->assertSame([], $array['mc_refund_amount']);
    }

    /**
     * 교환 신청서를 리소스 배열로 변환합니다. (차액 합산은 `exchangeOptions` 관계를 읽는다)
     *
     * @param  array<int, array<string, mixed>>  $optionRows  신청 상품 행
     * @return array<string, mixed> 응답 배열
     */
    private function exchangeToArray(array $optionRows): array
    {
        $exchange = OrderExchange::make([
            'exchange_number' => 'EX-TEST-D14',
            'exchange_status' => ExchangeStatusEnum::REQUESTED,
            'currency_code' => 'KRW',
            'additional_product_amount' => 4000,
            'refund_product_amount' => 3000,
        ]);

        $exchange->setRelation('exchangeOptions', collect(array_map(
            static fn (array $row) => OrderExchangeOption::make($row),
            $optionRows
        )));

        return (new ClaimResource($exchange))
            ->withReasonLabels([])
            ->toArray(Request::create('/'));
    }

    /**
     * 교환 차액 2줄의 보조 통화는 **서식을 입은 채** 내려간다. (S3-D14)
     *
     * 이 맵은 `sumMultiCurrency()` 가 만든 맨숫자(`['USD' => 3]`)라 그대로 내보내면 `.formatted` 가
     * 없다. 화면(`_list.json`)은 `[1]?.formatted ?? [1]` 로 폴백하므로 **오류 없이 기호도 자릿수도
     * 없는 `3` `345` 를 그린다** — 바로 위 형제 줄이 `$6.00 ¥690` 인데 이 줄만 맨숫자가 되지만,
     * 둘 다 정상 렌더라 응답에도 화면에도 어긋난 흔적이 남지 않는다(2026-09-16 3-S 실측).
     *
     * 되돌림 축: `formatStoredMultiCurrency()` 를 벗기면 `amount`·`formatted` 키가 사라져 red.
     *
     * @scenario claim_type=exchange, amount_source=server_summed, evidence=present
     *
     * @effects exchange_price_difference_secondary_currency_is_formatted
     */
    public function test_exchange_price_difference_secondary_currency_carries_formatted_text(): void
    {
        $array = $this->exchangeToArray([
            // 양수분(추가 청구) — 고가 옵션으로 바꾼 차액
            ['price_difference' => 4000, 'mc_price_difference' => ['KRW' => 4000, 'USD' => 4, 'JPY' => 460]],
            // 음수분(환불) — 저가 옵션으로 바꾼 차액. 화면이 `−` 를 붙이므로 절대값으로 나온다.
            ['price_difference' => -3000, 'mc_price_difference' => ['KRW' => -3000, 'USD' => -3, 'JPY' => -345]],
        ]);

        foreach (['mc_additional_product_amount', 'mc_refund_product_amount'] as $key) {
            $this->assertArrayHasKey($key, $array, $key.' 가 없으면 화면이 그 줄을 세우지 못한다.');
            $this->assertArrayHasKey('formatted', $array[$key]['USD'], $key.' 의 보조 통화에 서식이 없으면 맨숫자로 그려진다.');
            $this->assertArrayHasKey('amount', $array[$key]['USD']);
        }

        // 부호별로 갈라 더한다 — 환불분은 절대값(화면이 `−` 를 붙인다).
        $this->assertEqualsWithDelta(4, $array['mc_additional_product_amount']['USD']['amount'], 0.001);
        $this->assertEqualsWithDelta(3, $array['mc_refund_product_amount']['USD']['amount'], 0.001);
        $this->assertEqualsWithDelta(345, $array['mc_refund_product_amount']['JPY']['amount'], 0.001);

        // 서식 문자열이 실제로 통화 기호를 달고 있어야 한다 (맨숫자 `3` 이 아니다).
        $this->assertMatchesRegularExpression(
            '/[^0-9.,\-]/',
            (string) $array['mc_refund_product_amount']['USD']['formatted'],
            '서식 문자열에 통화 기호가 없으면 화면에서 맨숫자와 구분되지 않는다.'
        );
    }

    /**
     * 근거가 없으면(관계 미로드) 만들어 내지 않는다.
     *
     * `formatStoredMultiCurrency(null)` 은 빈 배열이라 화면이 그 줄을 세우지 않는다.
     *
     * @scenario claim_type=exchange, amount_source=server_summed, evidence=absent
     *
     * @effects secondary_currency_is_empty_when_nothing_was_stored
     */
    public function test_exchange_price_difference_secondary_currency_is_empty_without_items(): void
    {
        $exchange = OrderExchange::make([
            'exchange_number' => 'EX-TEST-D14-EMPTY',
            'exchange_status' => ExchangeStatusEnum::REQUESTED,
            'currency_code' => 'KRW',
            'additional_product_amount' => 0,
            'refund_product_amount' => 0,
        ]);

        $array = (new ClaimResource($exchange))->withReasonLabels([])->toArray(Request::create('/'));

        $this->assertSame([], $array['mc_additional_product_amount']);
        $this->assertSame([], $array['mc_refund_product_amount']);
    }
}
