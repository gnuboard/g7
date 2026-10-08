<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 순차 반품 회차 환불신청서의 통화별 금액 맵 (S3-D13, 2026-10-07 실측)
 *
 * 2회차 이후 반품의 환불신청서는 「누적 결과 − 앞 회차 결과」로 자기 몫을 낸다. 통화별 맵은 두 맵을 통화마다 빼서
 * 만드는데, 반올림 없이 빼서 `9.660000000000002` 같은 이진 부동소수 꼬리가 그대로 저장됐다(EC-486 이 없앤 것과
 * 같은 꼴). 또 마일리지를 더 돌려줄 수 없어 그 몫을 현금으로 옮길 때(`clampDeltaToRemaining`) 기준 통화 현금액만
 * 바뀌고 통화별 현금 맵은 옮기기 전 값으로 남았다.
 *
 * 실측: 주문 20261006-1552426446 의 2회차 반품 환불신청서 RF20261007-0007263118 — `refund_amount` 23,000 인데
 * `mc_refund_amount` 는 {"KRW":13000,"USD":9.660000000000002,…}. 고객 화면의 다통화 병기는 이 맵을 읽으므로
 * 23,000원 옆에 $9.66(13,000원 몫)이 찍힌다.
 */
class SequentialReturnRefundCurrencyMapTest extends ModuleTestCase
{
    /**
     * 실측 주문과 같은 통화 스냅샷.
     *
     * @return array 통화 스냅샷
     */
    private function snapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.742721, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1],
                'JPY' => ['rate' => 114.122682, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 100],
                'EUR' => ['rate' => 0.640693, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1],
            ],
        ];
    }

    /**
     * 차분과 잔여 맞춤을 실제 접수 경로와 같은 순서로 부릅니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $full  누적 결과
     * @param  AdjustmentResult  $prior  앞 회차 결과
     * @return array 이 신청서 몫
     */
    private function share(Order $order, AdjustmentResult $full, AdjustmentResult $prior): array
    {
        $service = app(OrderReturnService::class);
        $delta = (fn () => $this->refundDeltaAgainstPrior($full, $prior, $order->currency_snapshot))->call($service);

        return (fn () => $this->clampDeltaToRemaining($order, $delta))->call($service);
    }

    /**
     * 통화별 맵 차분에 부동소수 꼬리가 남지 않는다
     *
     * @scenario delta=rounded
     *
     * @effects sequential_return_currency_map_is_rounded_per_currency
     *
     * @return void 반환값 없음
     */
    public function test_currency_map_difference_is_rounded_to_each_currency(): void
    {
        $order = OrderFactory::new()->create([
            'currency_snapshot' => $this->snapshot(),
            'total_paid_amount' => 33000,
            'total_points_used_amount' => 0,
        ]);

        $full = new AdjustmentResult(refundAmount: 23000, mcRefundAmount: ['KRW' => 23000, 'USD' => 17.08, 'JPY' => 2624, 'EUR' => 14.74]);
        $prior = new AdjustmentResult(refundAmount: 10000, mcRefundAmount: ['KRW' => 10000, 'USD' => 7.42, 'JPY' => 1141, 'EUR' => 6.4]);

        $mc = $this->share($order, $full, $prior)['mc_refund_amount'];

        $this->assertSame(13000.0, (float) $mc['KRW']);
        $this->assertSame('9.66', json_encode($mc['USD']), '통화 소수 자릿수에서 끊겨야 한다(9.659999999999998 아님)');
        $this->assertSame('8.34', json_encode($mc['EUR']));
    }

    /**
     * 마일리지 몫을 현금으로 옮기면 통화별 현금 맵도 같은 금액을 말한다
     *
     * @scenario delta=points_moved_to_cash
     *
     * @effects moved_points_to_cash_moves_currency_map
     *
     * @return void 반환값 없음
     */
    public function test_moving_points_to_cash_moves_the_currency_map_too(): void
    {
        // 마일리지 10,000원은 앞 회차가 이미 모두 돌려줬다 — 이번 몫의 마일리지 10,000은 현금으로 옮겨진다.
        $order = OrderFactory::new()->create([
            'currency_snapshot' => $this->snapshot(),
            'total_paid_amount' => 33000,
            'total_refunded_amount' => 10000,
            'total_points_used_amount' => 10000,
            'total_refunded_points_amount' => 10000,
        ]);
        $currency = app(CurrencyConversionService::class);
        $mcOf = fn (float $amount) => $currency->convertToMultiCurrencyWithSnapshot($amount, $this->snapshot());

        $full = new AdjustmentResult(refundAmount: 23000, refundPointsAmount: 10000, mcRefundAmount: $mcOf(23000), mcRefundPointsAmount: $mcOf(10000));
        $prior = new AdjustmentResult(refundAmount: 10000, refundPointsAmount: 0, mcRefundAmount: $mcOf(10000), mcRefundPointsAmount: $mcOf(0));

        $share = $this->share($order, $full, $prior);

        $this->assertSame(23000.0, (float) $share['refund_amount']);
        $this->assertSame(0.0, (float) $share['refund_points_amount']);
        $this->assertSame(23000.0, (float) $share['mc_refund_amount']['KRW'], '기준 통화 맵이 현금액과 같아야 한다');
        $this->assertEquals($mcOf(23000)['USD'], $share['mc_refund_amount']['USD']);
        $this->assertEquals(0, $share['mc_refund_points_amount']['USD']);
    }
}
