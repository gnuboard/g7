<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use stdClass;

/**
 * 저장된 다중 통화 금액(`mc_*`)의 빈 맵 응답 모양 테스트 (코어 #758 Q5 — 이커머스 1.3.1)
 *
 * `mc_*` 는 통화 코드를 이름표로 값을 꺼내는 맵이다. 비었을 때 `[]` 로 나가면 API 문서(claims.md)가 약속한
 * `{}` 와 어긋나고, 화면의 키 접근 기본값이 동작하지 않는다. 값이 있으면 종전 모양 그대로다.
 *
 * 소유 시나리오: `multi-currency-empty-map`
 */
class MultiCurrencyEmptyMapTest extends ModuleTestCase
{
    /**
     * 통화 설정 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    // 이 테스트가 함께 검증하는 조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario stored=empty
    // @scenario stored=non_numeric

    /**
     * 저장값이 없거나 비었거나 숫자가 하나도 없으면 `{}` 를 낸다.
     *
     * @scenario stored=null
     *
     * @effects stored_multi_currency_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_empty_stored_multi_currency_is_an_object(): void
    {
        $formatter = $this->formatter();

        foreach ([null, [], ['KRW' => 'n/a']] as $stored) {
            $this->assertEquals(new stdClass, $formatter->format($stored), json_encode($stored));
            $this->assertSame('{}', json_encode($formatter->format($stored)));
        }
    }

    /**
     * 값이 있으면 종전처럼 통화 코드별 `{amount, formatted}` 맵(배열)이다.
     *
     * @scenario stored=filled
     *
     * @effects stored_multi_currency_filled_keeps_shape
     *
     * @return void 반환값 없음
     */
    public function test_filled_stored_multi_currency_keeps_shape(): void
    {
        $result = $this->formatter()->format(['KRW' => 10000]);

        $this->assertIsArray($result);
        $this->assertSame(10000, $result['KRW']['amount']);
        $this->assertArrayHasKey('formatted', $result['KRW']);
    }

    /**
     * 교환 신청서처럼 환불 행이 없는 신청서의 `mc_refund_amount` 대체값도 `{}` 다.
     *
     * @scenario stored=null
     *
     * @effects stored_multi_currency_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_refund_claim_without_amounts_serializes_empty_maps(): void
    {
        $refund = OrderRefund::make([
            'refund_number' => 'RF-EMPTY-1',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'mc_refund_amount' => null,
            'mc_refund_points_amount' => null,
        ]);

        $json = json_encode((new ClaimResource($refund))->withReasonLabels([])->toArray(Request::create('/')));

        $this->assertStringContainsString('"mc_refund_amount":{}', $json);
        $this->assertStringContainsString('"mc_refund_total":{}', $json);
        $this->assertStringNotContainsString('"mc_refund_amount":[]', $json);
    }

    /**
     * 계산 비교 블록의 다통화 줄(`calculation_comparison.mc_original`·`mc_recalculated`)도 비어 있는 `mc_*` 맵을
     * `{}` 로 낸다. 표시기 결과는 서비스가 PHP 배열로 읽으므로 응답에서만 바꾼다.
     *
     * @scenario stored=empty
     *
     * @effects stored_multi_currency_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_calculation_comparison_multi_currency_rows_serialize_empty_maps(): void
    {
        // 환산 결과에 실결제 금액만 있다 — 나머지 줄(소계·정가)은 빈 맵으로 남는다
        $currency = $this->createMock(CurrencyConversionService::class);
        $currency->method('convertMultipleAmountsWithSnapshot')->willReturn([
            'USD' => ['mc_total_paid_amount' => 7.5, 'mc_total_paid_amount_formatted' => '$7.50'],
        ]);
        $currency->method('formatPrice')->willReturn('$7.50');
        $this->app->instance(CurrencyConversionService::class, $currency);

        $refund = OrderRefund::make([
            'refund_number' => 'RF-EMPTY-2',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'currency_code' => 'KRW',
            'original_calculation_snapshot' => ['subtotal_amount' => 1000, 'total_paid_amount' => 1000],
            'recalculated_snapshot' => ['subtotal_amount' => 0, 'total_paid_amount' => 0],
        ]);
        $refund->setRelation('order', Order::make(['currency_code' => 'KRW', 'currency_snapshot' => ['base_currency' => 'KRW']]));

        $data = (new ClaimResource($refund))->withReasonLabels([])->toArray(Request::create('/'));

        foreach (['mc_original', 'mc_recalculated'] as $row) {
            $json = json_encode($data['calculation_comparison'][$row] ?? null);

            $this->assertStringContainsString('"mc_subtotal_amount":{}', $json, $row);
            $this->assertStringContainsString('"mc_total_list_price_amount_formatted":{}', $json, $row);
            $this->assertStringContainsString('"mc_total_paid_amount":{"USD":7.5}', $json, "{$row}: 값이 있는 맵은 종전 모양");
        }
    }

    /**
     * 보호 메서드를 부르는 포맷터를 만듭니다.
     *
     * @return object `format(?array): array|stdClass` 를 가진 객체
     */
    private function formatter(): object
    {
        return new class
        {
            use HasMultiCurrencyPrices;

            /**
             * 저장된 다중 통화 금액을 포맷합니다.
             *
             * @param  array<string, mixed>|null  $stored  저장값
             * @return array<string, mixed>|stdClass 포맷 결과
             */
            public function format(?array $stored): array|stdClass
            {
                return $this->formatStoredMultiCurrency($stored);
            }
        };
    }
}
