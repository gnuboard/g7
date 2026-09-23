<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimPaymentRepository;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 금액·동시성 결함 수정 검증 — 승인 대조·조건부 갱신·망취소·환급 잠금·반올림 (#599 validate P2-B)
 *
 * 검증 축:
 * - 승인 금액을 돌려주지 않은 승인은 확정하지 않는다(#11).
 * - 승인 실패·확정 기록은 결제 대기일 때만 쓴다(조건부 갱신).
 * - API 승인 경로의 금액 거절은 망취소 훅으로 보상한다.
 * - 부분 환급은 결제 통화 자릿수로, 누적 차분으로 환산한다(#9).
 * - 환급은 결제 건 행 잠금 안에서 PG 를 부른다.
 * - 마일리지 차감·환급은 결제 건 행을 잠근 트랜잭션에서 멱등하다(#10).
 */
class ValidateP2MoneyPaymentTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected ClaimPaymentService $service;

    /**
     * 서비스와 테스트 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(ClaimPaymentService::class);
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

    // ── 헬퍼 ──

    /**
     * 회수비 3,000 원을 신청 시점에 카드로 결제하는 반품과 그 결제 대기 건을 만듭니다.
     *
     * @return array{0: OrderReturn, 1: ClaimPayment} 신청서와 결제 대기 건
     */
    private function pendingCardPayment(): array
    {
        $this->registerTestPgProvider();
        $order = $this->deliveredClaimOrder();

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return [$return, $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value)];
    }

    /**
     * 기준 KRW · 결제 JPY(0 자리) 주문의 결제 완료 카드 건을 만듭니다. (청구 10,000원 = ¥1,001)
     *
     * @return ClaimPayment 결제 완료 건
     */
    private function paidJpyCardPayment(): ClaimPayment
    {
        $order = Order::factory()->create([
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'JPY',
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                    'JPY' => ['rate' => 0.1001, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                ],
            ],
        ]);

        return ClaimPayment::factory()->paid()->create([
            'order_id' => $order->id,
            'amount' => 10000,
            'currency_code' => 'KRW',
            'mc_amount' => ['KRW' => 10000, 'JPY' => 1001],
        ]);
    }

    // ── #11 승인 금액 미반환 ──

    /**
     * 승인 금액(`paid_amount`)을 돌려주지 않은 승인을 불일치로 거절하는지 검증합니다.
     *
     * 수정 전에는 키가 없으면 대조를 건너뛰어 얼마가 승인됐든 결제 완료로 확정했다.
     *
     * @scenario defect=approve_amount_missing
     *
     * @effects approval_without_paid_amount_is_rejected_as_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_승인_금액을_돌려주지_않은_승인은_거절된다(): void
    {
        [, $payment] = $this->pendingCardPayment();

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', fn (array $default) => array_merge($default, [
            'handled' => true,
            'success' => true,
            'transaction_id' => 'TXN-NO-AMOUNT',
        ]));

        $this->assertClaimException('claim_payment_amount_mismatch', fn () => $this->service->approvePgPayment($payment, []));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    // ── 조건부 갱신 ──

    /**
     * PG 왕복 사이에 다른 요청이 확정한 건에 실패 기록을 덮어쓰지 않는지 검증합니다.
     *
     * 수정 전에는 실패 분기가 상태를 보지 않고 갱신해, 결제 완료 행에 실패 코드가 적혔다.
     *
     * @scenario defect=approve_conditional_update
     *
     * @effects approval_failure_does_not_overwrite_a_paid_row
     *
     * @return void 반환값 없음
     */
    public function test_실패_기록은_이미_확정된_건을_덮지_않는다(): void
    {
        [, $payment] = $this->pendingCardPayment();

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, ClaimPayment $locked) {
            // 동시 승인 요청이 먼저 확정했다.
            DB::table('ecommerce_claim_payments')->where('id', $locked->id)->update([
                'payment_status' => ClaimPaymentStatusEnum::PAID->value,
                'transaction_id' => 'TXN-FIRST',
            ]);

            return array_merge($default, ['handled' => true, 'success' => false, 'error_code' => 'DECLINED', 'error_message' => 'late duplicate']);
        });

        $this->assertClaimException('claim_payment_failed', fn () => $this->service->approvePgPayment($payment, []));

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertNull($fresh->pg_error_code, '결제 완료된 행에 늦게 도착한 실패 코드가 적혔습니다.');
    }

    /**
     * PG 왕복 사이에 다른 요청이 확정한 건을 이 요청이 다시 확정하지 않는지 검증합니다.
     *
     * 수정 전에는 상태를 보지 않고 다시 확정해 결제 완료 훅·전이가 한 번 더 났다.
     *
     * @scenario defect=approve_conditional_update
     *
     * @effects approval_does_not_confirm_an_already_paid_row_twice
     *
     * @return void 반환값 없음
     */
    public function test_이미_확정된_건은_다시_확정하지_않는다(): void
    {
        [, $payment] = $this->pendingCardPayment();
        $expected = $this->service->resolvePaymentCharge($payment)['minor_unit_amount'];
        $fired = 0;

        HookManager::addAction('sirsoft-ecommerce.claim.return.payment_completed', function () use (&$fired) {
            $fired++;
        });

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, ClaimPayment $locked) use ($expected) {
            DB::table('ecommerce_claim_payments')->where('id', $locked->id)->update([
                'payment_status' => ClaimPaymentStatusEnum::PAID->value,
                'transaction_id' => 'TXN-FIRST',
            ]);

            return array_merge($default, ['handled' => true, 'success' => true, 'transaction_id' => 'TXN-SECOND', 'paid_amount' => $expected]);
        });

        $result = $this->service->approvePgPayment($payment, []);

        $this->assertSame(0, $fired, '먼저 확정한 요청이 이미 끝낸 확정을 이 요청이 다시 했습니다.');
        $this->assertSame('TXN-FIRST', $result->transaction_id);
    }

    // ── API 승인 경로 망취소 ──

    /**
     * PG 가 승인했지만 금액이 달라 거절한 건에 망취소 훅을 부르는지 검증합니다.
     *
     * 수정 전에는 API 승인 경로에 보상이 없어 고객은 청구된 채 실패 화면을 봤다.
     *
     * @scenario defect=approve_void_compensation
     *
     * @effects rejected_approval_is_voided_through_the_hook
     *
     * @return void 반환값 없음
     */
    public function test_금액_거절된_승인은_망취소_훅으로_보상한다(): void
    {
        [, $payment] = $this->pendingCardPayment();
        $voids = [];

        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 1]);
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.void_approval', function (array $default, ClaimPayment $target, array $callback, array $approve) use (&$voids) {
            $voids[] = ['payment_number' => $target->payment_number, 'transaction_id' => $approve['transaction_id'] ?? null];

            return array_merge($default, ['handled' => true, 'success' => true]);
        });

        $this->assertClaimException('claim_payment_amount_mismatch', fn () => $this->service->approvePgPayment($payment, ['paymentKey' => 'pk']));

        $this->assertCount(1, $voids);
        $this->assertSame($payment->payment_number, $voids[0]['payment_number']);
        $this->assertSame('TXN-'.$payment->payment_number, $voids[0]['transaction_id']);
    }

    /**
     * 콜백 경로처럼 PG 별 보상을 따로 드는 호출은 망취소 훅을 부르지 않는지 검증합니다. (이중 취소 방지)
     *
     * @scenario defect=approve_void_compensation
     *
     * @effects callback_path_with_its_own_compensation_does_not_void_twice
     *
     * @return void 반환값 없음
     */
    public function test_자체_보상을_드는_호출은_망취소_훅을_부르지_않는다(): void
    {
        [, $payment] = $this->pendingCardPayment();
        $voids = 0;

        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 1]);
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.void_approval', function (array $default) use (&$voids) {
            $voids++;

            return $default;
        });

        $this->assertClaimException('claim_payment_amount_mismatch', fn () => $this->service->approvePgPayment($payment, [], voidOnReject: false));
        $this->assertSame(0, $voids);
    }

    // ── #9 부분 환급 반올림 ──

    /**
     * 0 자리 결제 통화의 부분 환급이 정수로 나가고 회차 합이 결제 총액과 같은지 검증합니다.
     *
     * 수정 전에는 소수 2자리로 반올림해 ¥333.63 을 PG 에 보냈다(0 자리 통화 거절 · 합계 불일치).
     *
     * @scenario defect=partial_refund_rounding
     *
     * @effects partial_refund_local_amounts_are_whole_and_sum_to_the_total
     *
     * @return void 반환값 없음
     */
    public function test_부분_환급은_결제_통화_자릿수로_잔액까지_나간다(): void
    {
        $payment = $this->paidJpyCardPayment();
        $calls = null;
        $this->fakeClaimPaymentRefund(true, $calls);

        foreach ([3333.0, 3333.0, 3334.0] as $amount) {
            $this->service->refundPayment($payment->fresh(), $amount, 'partial');
        }

        $this->assertCount(3, $calls);

        foreach ($calls as $call) {
            $this->assertSame(0.0, fmod((float) $call['amount'], 1.0), '0 자리 통화에 소수 금액을 보냈습니다: '.$call['amount']);
        }

        $this->assertEqualsWithDelta(1001.0, array_sum(array_column($calls, 'amount')), 0.0001);
        $this->assertSame(ClaimPaymentStatusEnum::REFUNDED, $payment->fresh()->payment_status);
    }

    // ── 환급 잠금 ──

    /**
     * PG 환급이 결제 건 행 잠금 트랜잭션 안에서 불리는지 검증합니다.
     *
     * 수정 전에는 잠금이 최신 행을 읽는 데서 끝나 PG 호출이 트랜잭션 밖이었다 — 같은 잔액을 읽은
     * 두 요청이 각자 다른 금액을 보내면 PG 가 둘을 한 번으로 접지 못한다.
     *
     * @scenario defect=refund_lock
     *
     * @effects pg_refund_runs_inside_the_payment_row_lock
     *
     * @return void 반환값 없음
     */
    public function test_p_g_환급은_결제_건_잠금_안에서_불린다(): void
    {
        $payment = $this->paidJpyCardPayment();
        $baseLevel = DB::transactionLevel();
        $observed = null;

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.refund', function (array $default) use (&$observed) {
            $observed = DB::transactionLevel();

            return array_merge($default, ['handled' => true, 'success' => true, 'transaction_id' => 'RTXN']);
        });

        $this->service->refundPayment($payment, 1000.0, 'lock');

        $this->assertNotNull($observed);
        $this->assertGreaterThan($baseLevel, $observed, 'PG 환급이 결제 건 잠금 트랜잭션 밖에서 불렸습니다.');
    }

    /**
     * PG 가 환급을 거절해도 거절 사유가 롤백 뒤에 남는지 검증합니다. (잠금 트랜잭션 도입의 회귀 방어)
     *
     * @scenario defect=refund_lock
     *
     * @effects pg_refund_failure_reason_survives_the_rollback
     *
     * @return void 반환값 없음
     */
    public function test_p_g_환급_거절_사유는_롤백_뒤에도_남는다(): void
    {
        $payment = $this->paidJpyCardPayment();
        $calls = null;
        $this->fakeClaimPaymentRefund(false, $calls);

        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPayment($payment, 1000.0, 'fail'));

        $fresh = $payment->fresh();
        $this->assertSame('REFUND_DECLINED', $fresh->pg_error_code);
        $this->assertSame(0.0, (float) $fresh->refunded_amount);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
    }

    // ── #10 마일리지 멱등 ──

    /**
     * 마일리지 환급 상한을 잠근 행에서 다시 읽는지 검증합니다.
     *
     * 수정 전에는 호출부가 들고 온 모델의 `points_amount` 로 상한을 잡아, 낡은 모델(5,000)이 넘어오면
     * 실제 결제액(3,000)보다 많이 돌려줬다 — 잠금 없는 확인-생성과 같은 뿌리의 결함이다.
     *
     * @scenario defect=mileage_idempotency
     *
     * @effects mileage_restore_is_capped_by_the_locked_row
     *
     * @return void 반환값 없음
     */
    public function test_마일리지_환급_상한은_잠근_행에서_읽는다(): void
    {
        $order = $this->deliveredClaimOrder();
        $payment = ClaimPayment::factory()->paid()->withPoints(3000)->create(['order_id' => $order->id]);

        $stale = clone $payment;
        $stale->points_amount = 5000;

        $tx = app(UserMileageService::class)->restoreForClaimPayment((int) $order->user_id, 5000.0, 'KRW', $stale);

        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(3000.0, (float) $tx->amount, 0.01);
    }

    /**
     * 마일리지 환급이 결제 건 행을 잠근 트랜잭션 안에서 기환급액을 세는지 검증합니다.
     *
     * 수정 전에는 잠금도 트랜잭션도 없이 「기환급액 확인 → 거래 생성」을 해, 동시 요청 둘이 같은
     * 기환급액을 읽고 각자 lot 을 발행할 수 있었다.
     *
     * @scenario defect=mileage_idempotency
     *
     * @effects mileage_restore_locks_the_claim_payment_row
     *
     * @return void 반환값 없음
     */
    public function test_마일리지_환급은_결제_건_행을_잠근다(): void
    {
        $order = $this->deliveredClaimOrder();
        $payment = ClaimPayment::factory()->paid()->withPoints(3000)->create(['order_id' => $order->id]);
        $baseLevel = DB::transactionLevel();

        $spy = new class(new ClaimPayment) extends ClaimPaymentRepository
        {
            /** @var array<int, int> findForUpdate 호출 시점의 트랜잭션 깊이 */
            public array $levels = [];

            /**
             * 호출 시점의 트랜잭션 깊이를 기록하고 실제 잠금 조회로 위임합니다.
             *
             * @param  int  $id  결제 건 ID
             * @return ClaimPayment|null 잠근 결제 건
             */
            public function findForUpdate(int $id): ?ClaimPayment
            {
                $this->levels[] = DB::transactionLevel();

                return parent::findForUpdate($id);
            }
        };

        $this->app->instance(ClaimPaymentRepositoryInterface::class, $spy);
        $service = $this->app->make(UserMileageService::class);

        $service->restoreForClaimPayment((int) $order->user_id, 3000.0, 'KRW', $payment);

        $this->assertNotEmpty($spy->levels, '환급이 결제 건 행을 잠그지 않았습니다.');
        $this->assertGreaterThan($baseLevel, $spy->levels[0]);
    }

    /**
     * 같은 결제 건으로 마일리지 차감을 두 번 불러도 한 번만 빠지는지 검증합니다.
     *
     * 수정 전에는 차감 자체에 멱등이 없어 호출부 잠금을 거치지 않은 두 번째 호출이 다시 뺐다.
     *
     * @scenario defect=mileage_idempotency
     *
     * @effects mileage_deduct_for_a_claim_payment_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_같은_결제_건의_마일리지_차감은_한_번이다(): void
    {
        $order = $this->deliveredClaimOrder();
        $userId = (int) $order->user_id;
        $this->grantMileage($userId, 10000);
        $payment = ClaimPayment::factory()->withPoints(3000)->create(['order_id' => $order->id]);

        $service = app(UserMileageService::class);
        $first = $service->deductForClaimPayment($userId, 3000, 'KRW', $payment);
        $second = $service->deductForClaimPayment($userId, 3000, 'KRW', $payment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MileageTransaction::where('claim_payment_id', $payment->id)
            ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
            ->count());
        $this->assertEqualsWithDelta(7000.0, (float) MileageTransaction::where('user_id', $userId)->sum('remaining_amount'), 0.01);
    }
}
