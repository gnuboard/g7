<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 음수 금액의 통화 절사 대칭성 테스트
 *
 * 차액·환불 차감처럼 음수로 실리는 금액도 같은 절사 규칙을 탄다. `floor()` 를 음수에 그대로 쓰면
 * 0 에서 멀어지는 쪽으로 깎여(−342.3 → −343), 교환 신청 요약에서 같은 3,000원이 「옵션 차액 ¥-343」 과
 * 「환불 예정액 ¥342」 로 1엔 어긋난다. 음수는 절댓값에 규칙을 적용한 뒤 부호를 되돌린다.
 */
class CurrencyNegativeAmountRoundingTest extends ModuleTestCase
{
    protected CurrencyConversionService $service;

    /**
     * 환산 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CurrencyConversionService;
    }

    /**
     * 절사·올림·반올림 모두 음수는 양수의 거울상이다
     *
     * @scenario base_currency=KRW, selected_currency=JPY, exchange_rate=configured, surface=checkout, verify_layer=api
     *
     * @effects negative_amount_rounds_symmetric_to_positive
     *
     * @return void 반환값 없음
     */
    public function test_negative_amount_rounds_as_mirror_of_positive(): void
    {
        $this->assertSame(342.0, $this->service->applyRounding(342.3, '1', 'floor'));
        $this->assertSame(-342.0, $this->service->applyRounding(-342.3, '1', 'floor'));

        $this->assertSame(343.0, $this->service->applyRounding(342.3, '1', 'ceil'));
        $this->assertSame(-343.0, $this->service->applyRounding(-342.3, '1', 'ceil'));

        $this->assertSame(1.88, $this->service->applyRounding(1.875, '0.01', 'round'));
        $this->assertSame(-1.88, $this->service->applyRounding(-1.875, '0.01', 'round'));
        $this->assertSame(-45.05, $this->service->applyRounding(-45.0599, '0.01', 'floor'));
    }

    /**
     * 0 으로 깎인 음수는 음의 0(-0.0)이 아닌 0 으로 돌아온다 — JSON 응답에 「-0」 이 실리지 않게
     *
     * @scenario base_currency=KRW, selected_currency=JPY, exchange_rate=configured, surface=checkout, verify_layer=api
     *
     * @effects negative_amount_rounds_symmetric_to_positive
     *
     * @return void 반환값 없음
     */
    public function test_negative_amount_truncated_to_zero_is_not_negative_zero(): void
    {
        // 음의 0 은 json_encode 에서 「-0」 으로 찍힌다.
        $this->assertSame('0', json_encode($this->service->applyRounding(-0.4, '1', 'floor')));
    }

    /**
     * 같은 3,000원을 엔화로 바꾸면 부호만 다른 같은 크기다 (교환 차액 −3,000원 ↔ 환불 예정 3,000원)
     *
     * @scenario base_currency=KRW, selected_currency=JPY, exchange_rate=configured, surface=checkout, verify_layer=api
     *
     * @effects negative_amount_rounds_symmetric_to_positive
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_conversion_of_negative_amount_mirrors_positive(): void
    {
        $snapshot = [
            'base_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                'JPY' => ['rate' => 0.1141, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
            ],
        ];

        $positive = $this->service->convertToMultiCurrencyWithSnapshot(3000, $snapshot);
        $negative = $this->service->convertToMultiCurrencyWithSnapshot(-3000, $snapshot);

        $this->assertSame(342.0, $positive['JPY']);
        $this->assertSame(-342.0, $negative['JPY']);
    }
}
