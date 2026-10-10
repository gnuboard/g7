<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 결제의 뒤처리 실패를 결제 건·상세에 드러낸다 (B4 · B5)
 *
 * - B4 금액 불일치로 거절한 승인을 망취소하지 못해도 로그 한 줄뿐이었다 — 고객 돈이 PG 에 묶인 사실이 결제 건에 없었다.
 * - B5 결제가 확정됐는데 신청서 전이가 거절되면 `Log::info` 로만 남아, 신청서가 결제대기에 멈춘 이유를 운영자가 알 수 없었다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-payment-failure-visibility.yaml`
 */
class ClaimPaymentFailureVisibilityTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
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
     * 회수비 3,000원 결제 대기 건과 반품을 만듭니다.
     *
     * @param  string  $method  결제 수단
     * @return array{0: OrderReturn, 1: ClaimPayment} [반품, 결제 대기 건]
     */
    private function pending(string $method): array
    {
        $order = $this->deliveredClaimOrder();
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return [$return, app(ClaimPaymentService::class)->initiateForRequest($return, $method)->fresh()];
    }

    /**
     * 금액 불일치 승인을 망취소하지 못하면 결제 건에 `VOID_FAILED` 가 남는다.
     *
     * @scenario item=b4_void_failed
     *
     * @effects void_failure_is_recorded_on_the_payment
     *
     * @return void 반환값 없음
     */
    public function test_void_failure_is_recorded_on_the_payment(): void
    {
        [, $payment] = $this->pending(PaymentMethodEnum::CARD->value);
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', fn (array $d) => array_merge($d, [
            'handled' => true, 'success' => true, 'transaction_id' => 'TX-B4', 'paid_amount' => 1,
        ]));
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.void_approval', fn (array $d) => array_merge($d, [
            'handled' => true, 'success' => false, 'error_code' => 'PG_DOWN',
        ]));

        try {
            app(ClaimPaymentService::class)->approvePgPayment($payment, ['paymentKey' => 'TX-B4']);
            $this->fail('금액 불일치가 통과했다');
        } catch (OrderClaimException) {
            // 기대한 거절
        }

        $this->assertSame('VOID_FAILED', $payment->fresh()->pg_error_code, '망취소 실패가 결제 건에 남지 않았다');
    }

    /**
     * 결제가 확정됐는데 신청서 전이가 거절되면 경고로 남기고, 상세는 「결제됨 · 단계 전이 실패」 를 알린다.
     *
     * @scenario item=b5_transition_failed
     *
     * @effects paid_claim_stuck_in_payment_pending_is_flagged
     *
     * @return void 반환값 없음
     */
    public function test_paid_claim_stuck_in_payment_pending_is_flagged(): void
    {
        [$return, $payment] = $this->pending(PaymentMethodEnum::POINT->value);
        MileageTransaction::create([
            'user_id' => $return->requested_by, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 10000, 'remaining_amount' => 10000, 'balance_after' => 10000,
        ]);
        HookManager::addAction('sirsoft-ecommerce.claim.return.before_status_change', function ($r, ?string $from, string $to) {
            if ($to === 'paid') {
                throw OrderClaimException::transitionNotAllowed($from ?? '', $to);
            }
        });
        Log::spy();

        app(ClaimPaymentService::class)->payWithMileage($payment, (int) $return->requested_by);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '결제 확정 전이 거절'))->once();

        $data = (new ClaimResource($return->fresh()))->toArray(request());
        $this->assertTrue($data['paid_but_transition_failed'], '결제대기에 멈춘 결제완료 신청서가 표시되지 않는다');
    }
}
