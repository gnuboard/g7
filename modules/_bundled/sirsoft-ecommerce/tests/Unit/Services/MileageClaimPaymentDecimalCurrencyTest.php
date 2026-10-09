<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 클레임 결제는 통화의 소수 자릿수로 차감한다 (S9)
 *
 * 예전에는 `(int) round(총액)` 으로 차감해, 소수 통화에서 청구 2.40 에 2 만 차감됐다. 결제 건은 PAID 인데 미수납
 * 0.40 이 남아 `confirmPaid` 가 신청서를 옮기지 않았고, 대기 행은 이미 PAID 라 남은 돈을 낼 곳이 없었다.
 *
 * 시나리오 매니페스트: `tests/scenarios/mileage-claim-payment-decimal-currency.yaml`
 */
class MileageClaimPaymentDecimalCurrencyTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 원화(0자리)·달러(2자리) 통화 설정과 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings([
            [
                'code' => 'KRW',
                'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                'exchange_rate' => null,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'decimal_places' => 0,
                'is_default' => true,
            ],
            [
                'code' => 'USD',
                'name' => ['ko' => 'USD (달러)', 'en' => 'USD (Dollar)'],
                'exchange_rate' => 0.001,
                'rounding_unit' => '0.01',
                'rounding_method' => 'round',
                'decimal_places' => 2,
                'is_default' => false,
            ],
        ]);
        $this->seedClaimReasons();
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 청구 2.40 USD 의 결제 대기 건을 만들고, 회원에게 10 USD 마일리지를 줍니다.
     *
     * @return ClaimPayment 결제 대기 건
     */
    private function pendingUsdPayment(): ClaimPayment
    {
        $order = $this->deliveredClaimOrder(returnFee: 3000);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);
        $payment = app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        // 기준 통화가 달러인 상점과 같은 숫자 — 청구액·결제 건 모두 2.40 USD
        $return->forceFill(['claim_shipping_fee' => 2.40, 'total_charge_amount' => 2.40])->save();
        $payment->forceFill(['amount' => 2.40, 'points_amount' => 0, 'currency_code' => 'USD'])->save();

        MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => 'USD',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 10,
            'remaining_amount' => 10,
            'balance_after' => 10,
        ]);

        return $payment->fresh();
    }

    /**
     * 2.40 USD 청구는 2.40 을 차감하고, 미수납 없이 신청서를 결제완료로 옮긴다.
     *
     * @scenario currency_decimals=two
     *
     * @effects mileage_claim_payment_rounds_to_currency_decimals
     *
     * @return void 반환값 없음
     */
    public function test_decimal_currency_charge_deducts_the_exact_amount_and_confirms(): void
    {
        $payment = $this->pendingUsdPayment();
        $return = $payment->payable;

        $paid = app(ClaimPaymentService::class)->payWithMileage($payment, (int) $return->order->user_id);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->fresh()->payment_status);
        $this->assertEquals(2.40, (float) $paid->fresh()->points_amount);
        $this->assertEquals(
            -2.40,
            (float) MileageTransaction::where('claim_payment_id', $payment->id)->value('amount'),
            '차감 거래가 정수로 잘렸다'
        );
        $this->assertSame(0.0, app(ClaimPaymentService::class)->outstandingAmount($return->fresh()));
        $this->assertNotSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status, '미수납이 남아 결제완료로 옮기지 못했다');
    }
}
