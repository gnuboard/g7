<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 결제 통화 총액·환급 누적액 접근자 테스트 (K3)
 *
 * `resolvePaymentCurrencyTotal()` · `resolveRefundedPaymentCurrencyTotal()` 는 결제 플러그인
 * 4종이 **부분/전액 취소 판정**에 쓰는 단일 출처다. 그런데 환산 분기를 재는 테스트가 하나도
 * 없어서, 플러그인이 위임을 버리고 사본을 되살려도 환산 조건을 바꿔도 전건 green 이었다.
 *
 * 분기 축:
 *  - 주문 통화 == 결제 통화        → 기준통화 총액 그대로
 *  - 주문 통화 != 결제 통화 + mc 값 있음 → 동결된 mc 값
 *  - 주문 통화 != 결제 통화 + mc 값 없음 → 기준통화 총액 (폴백)
 *  - 총액 0                        → 환산하지 않는다
 *  - 스냅샷 부재                    → 결제 통화를 주문 통화로 본다 (같은 통화 취급)
 */
class ClaimPaymentCurrencyTotalTest extends ModuleTestCase
{
    private ClaimPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ClaimPaymentService::class);
    }

    /**
     * 주어진 스냅샷·금액 조합의 결제 건을 만듭니다.
     *
     * @param  array<string, mixed>|null  $snapshot  주문 통화 스냅샷
     * @param  array<string, float>|null  $mcAmount  통화별 동결 금액
     * @param  float  $amount  PG 결제 금액 (기준통화)
     * @param  float  $points  마일리지 결제 금액 (기준통화)
     * @param  float  $refunded  환급 누적액 (기준통화)
     */
    private function payment(
        ?array $snapshot,
        ?array $mcAmount,
        float $amount = 10000,
        float $points = 0,
        float $refunded = 0,
    ): ClaimPayment {
        $order = Order::factory()->create(['currency_snapshot' => $snapshot]);

        return ClaimPayment::factory()->create([
            'order_id' => $order->id,
            'currency_code' => 'KRW',
            'amount' => $amount,
            'points_amount' => $points,
            'mc_amount' => $mcAmount,
            'refunded_amount' => $refunded,
        ]);
    }

    /**
     * 주문 통화가 결제 통화와 같으면 환산하지 않는다.
     */
    #[Test]
    public function same_currency_returns_the_base_total(): void
    {
        $payment = $this->payment(['order_currency' => 'KRW'], ['USD' => 7.5]);

        $this->assertSame(10000.0, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 주문 통화가 다르고 동결 금액이 있으면 그 값을 쓴다.
     */
    #[Test]
    public function foreign_currency_with_a_frozen_amount_uses_it(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5]);

        $this->assertSame(7.5, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 주문 통화가 다른데 그 통화의 동결 금액이 없으면 기준통화 총액으로 떨어진다.
     */
    #[Test]
    public function foreign_currency_without_a_frozen_amount_falls_back_to_the_base_total(): void
    {
        $payment = $this->payment(['order_currency' => 'JPY'], ['USD' => 7.5]);

        $this->assertSame(10000.0, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 총액이 0 이면 환산하지 않는다 — 비율 계산의 분모가 되기 때문이다.
     */
    #[Test]
    public function a_zero_total_is_not_converted(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5], amount: 0);

        $this->assertSame(0.0, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 스냅샷이 없으면 결제 통화를 주문 통화로 보고 환산하지 않는다.
     */
    #[Test]
    public function a_missing_snapshot_is_treated_as_the_payment_currency(): void
    {
        $payment = $this->payment(null, ['USD' => 7.5]);

        $this->assertSame(10000.0, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 마일리지 결제분도 총액에 포함된 채로 환산 분모가 된다.
     */
    #[Test]
    public function the_total_includes_the_mileage_portion(): void
    {
        $payment = $this->payment(['order_currency' => 'KRW'], null, amount: 6000, points: 4000);

        $this->assertSame(10000.0, $this->service->resolvePaymentCurrencyTotal($payment));
    }

    /**
     * 환급 누적액은 결제 통화 총액에 대한 비율로 환산된다.
     *
     * 10,000 중 2,500 환급(25%) → 7.5 USD 의 25% = 1.88 (소수 2자리 반올림).
     */
    #[Test]
    public function refunded_total_is_converted_proportionally(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5], refunded: 2500);

        $this->assertSame(1.88, $this->service->resolveRefundedPaymentCurrencyTotal($payment));
    }

    /**
     * 같은 통화면 환급 누적액도 기준통화 값 그대로다.
     */
    #[Test]
    public function refunded_total_in_the_same_currency_is_the_base_amount(): void
    {
        $payment = $this->payment(['order_currency' => 'KRW'], null, refunded: 2500);

        $this->assertSame(2500.0, $this->service->resolveRefundedPaymentCurrencyTotal($payment));
    }

    /**
     * 환급이 없으면 0 이다 — 전액 취소 판정이 부분 취소로 갈리지 않도록 고정한다.
     */
    #[Test]
    public function no_refund_yields_zero(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5]);

        $this->assertSame(0.0, $this->service->resolveRefundedPaymentCurrencyTotal($payment));
    }

    /**
     * 총액이 0 이면 환급 누적액도 0 이다 (0 나눗셈 회피).
     */
    #[Test]
    public function a_zero_total_yields_zero_refunded(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5], amount: 0, refunded: 100);

        $this->assertSame(0.0, $this->service->resolveRefundedPaymentCurrencyTotal($payment));
    }

    /**
     * 전액 환급이면 결제 통화 총액과 같아진다 — 플러그인의 "전액 취소" 판정이 서는 자리다.
     */
    #[Test]
    public function a_full_refund_equals_the_payment_currency_total(): void
    {
        $payment = $this->payment(['order_currency' => 'USD'], ['USD' => 7.5], refunded: 10000);

        $this->assertSame(
            $this->service->resolvePaymentCurrencyTotal($payment),
            $this->service->resolveRefundedPaymentCurrencyTotal($payment),
            '전액 환급인데 두 값이 다르면 플러그인이 부분 취소로 오판한다'
        );
    }
}
