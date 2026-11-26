<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\ClaimPaymentLookup;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ClaimPaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\MetaEncoding;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentBrief;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentCompletion;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ReceiptRequester;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreRequest;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\VbankIssuance;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\InvalidPaymentTransition;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\OrderAccess;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLifecycle;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support\RetryRestoreScenarios;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 결제사 플러그인 결제 공개 계약 
 *
 * 계약 구현은 기존 이커머스 서비스에 위임만 하고(동작 같음), 재결제 복구만 정상 전이로 바뀐다.
 * 복구 meta 는 결제사 현행 코드로 녹화한 값(tests/Fixtures/payment-retry-restore)과 DB 원문이 같아야 한다.
 */
class PaymentGatewayBridgeTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 시계 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 주문과 결제 행을 만듭니다.
     *
     * @param  array<string, mixed>  $payment  결제 행 값
     * @param  array<string, mixed>  $order  주문 값
     * @return Order 주문(결제 행 적재)
     */
    private function makeOrder(array $payment = [], array $order = []): Order
    {
        $model = OrderFactory::new()->create($order + ['order_status' => OrderStatusEnum::PENDING_ORDER, 'paid_at' => null]);
        OrderPaymentFactory::new()->forOrder($model)->create($payment + [
            'pg_provider' => 'nhnkcp',
            'payment_status' => PaymentStatusEnum::READY,
            'transaction_id' => null,
            'card_approval_number' => null,
            'paid_at' => null,
            'cancelled_at' => null,
            'payment_meta' => ['mid' => 'm1'],
        ]);

        return $model->fresh(['payment']);
    }

    /**
     * 묶음 계약과 하위 인터페이스가 같은 구현 인스턴스로 풀리고 계약 판이 1 이다.
     *
     * @scenario case=bridge_and_sub_interfaces_resolve_to_same_instance
     *
     * @effects payment_contract_sub_interfaces_share_one_instance
     *
     * @return void 반환값 없음
     */
    public function test_bridge_and_sub_interfaces_resolve_to_same_instance(): void
    {
        $bridge = app(PaymentGatewayBridge::class);

        $this->assertSame($bridge, app(PaymentLookup::class));
        $this->assertSame($bridge, app(OrderAccess::class));
        $this->assertSame($bridge, app(ClaimPaymentLookup::class));
        $this->assertSame($bridge, app(PaymentLifecycle::class));
        $this->assertSame(1, PaymentGatewayBridge::VERSION);
    }

    // ───────────────────────────────────────────────
    // 재결제 복구 — 특성화 대조
    // ───────────────────────────────────────────────

    /**
     * 녹화된 시나리오 목록입니다.
     *
     * @return array<string, array{0: string, 1: string}> 결제사 id, 시나리오
     */
    public static function recordedScenarios(): array
    {
        $cases = [];
        foreach (RetryRestoreScenarios::all() as $pg => $names) {
            foreach ($names as $name) {
                $cases["{$pg}:{$name}"] = [$pg, $name];
            }
        }

        return $cases;
    }

    /**
     * 계약 복구 결과가 결제사 현행 복구 녹화값과 DB 원문까지 같다. 다른 것은 의도된 변화 하나 —
     * 결제 실패 처리가 취소로 바꾼 주문 상품이 주문 대기로 돌아온다(현행은 취소로 남아 재결제 성공 뒤에도 상품이 취소로 남았다).
     *
     * @param  string  $pg  결제사 id
     * @param  string  $scenario  시나리오 이름
     *
     * @scenario case=restore_for_retry_matches_recorded_legacy_bytes
     *
     * @effects retry_restore_meta_matches_legacy_recording
     *
     * @return void 반환값 없음
     */
    #[DataProvider('recordedScenarios')]
    public function test_restore_for_retry_matches_recorded_legacy_bytes(string $pg, string $scenario): void
    {
        $fixture = RetryRestoreScenarios::fixturePath($pg, $scenario);
        $this->assertFileExists($fixture, '녹화 파일이 없습니다 — G7_CHARACTERIZE=record 로 녹화기를 먼저 돌리세요.');
        $expected = json_decode((string) file_get_contents($fixture), true)['snapshot'];

        $order = RetryRestoreScenarios::prepare($pg, $scenario);
        $request = $pg === 'nhnkcp'
            ? new RetryRestoreRequest('nhnkcp', null, clearTransaction: true, recordFailureStage: true)
            : new RetryRestoreRequest('nicepayments');

        $result = app(PaymentLifecycle::class)->restoreForRetry($order->order_number, $pg, $request);
        $this->assertTrue($result->restored);

        $actual = RetryRestoreScenarios::snapshot($order);

        // 의도된 차이: 상품 상태(녹화는 현행 결함 그대로 cancelled)
        $this->assertSame(array_fill(0, count($expected['option_statuses']), 'cancelled'), $expected['option_statuses']);
        $this->assertSame(array_fill(0, count($actual['option_statuses']), 'pending_order'), $actual['option_statuses']);
        unset($expected['option_statuses'], $actual['option_statuses']);

        $this->assertSame($expected, $actual);
    }

    /**
     * 복구는 주문 상태 전이 훅, 재결제 복구 훅, 상품 동기화 훅을 발화하고 활동 로그를 남긴다. 같은 주문을 다시 복구하면 거부된다.
     *
     * @scenario case=restore_fires_transition_hooks_and_activity_log
     *
     * @effects retry_restore_fires_transition_hooks_and_logs
     *
     * @return void 반환값 없음
     */
    public function test_restore_fires_transition_hooks_and_activity_log(): void
    {
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/orders'));
        $fired = [];
        foreach (['order.after_status_change', 'order.payment_retry_restored', 'order_option.after_sync_status'] as $hook) {
            HookManager::addAction('sirsoft-ecommerce.'.$hook, function (...$args) use (&$fired, $hook) {
                $fired[$hook] = $args;
            });
        }

        $order = RetryRestoreScenarios::prepare('nhnkcp', 'single');
        $result = app(PaymentLifecycle::class)->restoreForRetry($order->order_number, 'nhnkcp', new RetryRestoreRequest('nhnkcp', null, true, true));

        $this->assertTrue($result->restored);
        $this->assertSame(1, $result->retryCount);
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $result->order?->orderStatus);
        $this->assertSame(['cancelled', 'pending_order'], [$fired['order.after_status_change'][1], $fired['order.after_status_change'][2]]);
        $this->assertSame('nhnkcp', $fired['order.payment_retry_restored'][1]);
        $this->assertCount(2, $fired['order_option.after_sync_status'][0]);
        $this->assertSame('payment_retry_restore', $fired['order_option.after_sync_status'][2]);

        // 활동 로그 리스너가 이 훅을 구독하고 기록한다
        $listener = app(OrderActivityLogListener::class);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.payment_retry_restored', $listener::getSubscribedHooks());
        $listener->handleOrderPaymentRetryRestored(...$fired['order.payment_retry_restored']);
        $this->assertTrue(ActivityLog::where('action', 'order.payment_retry_restored')->where('loggable_id', $order->id)->exists());

        $again = app(PaymentLifecycle::class)->restoreForRetry($order->order_number, 'nhnkcp', new RetryRestoreRequest('nhnkcp', null, true, true));
        $this->assertFalse($again->restored);
        $this->assertSame('not_restorable_state', $again->reason);
    }

    /**
     * 결제 완료 이력(결제 시각)과 부분 취소 이력(누적 취소액, 상품 취소 수량)이 있으면 복구를 거부한다.
     * 창 닫힘이 남긴 결제 취소 이력(USER_CANCEL)은 막지 않는다(특성화 시나리오가 모두 복구됨).
     *
     * @scenario case=restore_rejects_paid_and_partial_cancel_history
     *
     * @effects retry_restore_rejects_paid_or_partial_cancel_history
     *
     * @return void 반환값 없음
     */
    public function test_restore_rejects_paid_and_partial_cancel_history(): void
    {
        $cases = [
            'paid_history' => fn (Order $o) => DB::table($o->payment->getTable())->where('order_id', $o->id)->update(['paid_at' => now()]),
            'partial_cancel_history' => fn (Order $o) => DB::table($o->payment->getTable())->where('order_id', $o->id)->update(['cancelled_amount' => 1000]),
            'option_cancelled_quantity' => fn (Order $o) => DB::table($o->options()->getRelated()->getTable())->where('order_id', $o->id)->limit(1)->update(['cancelled_quantity' => 1]),
        ];

        foreach ($cases as $name => $mutate) {
            $order = RetryRestoreScenarios::prepare('nicepayments', 'single');
            DB::table($order->getTable())->where('id', $order->id)->update(['order_number' => 'RT-REJECT-'.$name]);
            $order = $order->fresh(['payment']);
            $mutate($order);

            try {
                app(PaymentLifecycle::class)->restoreForRetry($order->order_number, 'nicepayments', new RetryRestoreRequest('nicepayments'));
                $this->fail("{$name}: 거부해야 합니다.");
            } catch (InvalidPaymentTransition $e) {
                $this->assertSame($name === 'paid_history' ? 'paid_history' : 'partial_cancel_history', $e->reason);
            }

            $this->assertSame('cancelled', DB::table($order->getTable())->where('id', $order->id)->value('order_status'));
        }
    }

    /**
     * 복구는 결제 행 소유 결제사가 같아야 하고, 금액 대조가 틀리면 복구하지 않으며, 접두는 결제사 id 와 같아야 한다.
     *
     * @scenario case=restore_provider_amount_and_prefix_guards
     *
     * @effects retry_restore_guards_provider_amount_prefix
     *
     * @return void 반환값 없음
     */
    public function test_restore_provider_amount_and_prefix_guards(): void
    {
        $order = RetryRestoreScenarios::prepare('nhnkcp', 'single');
        $lifecycle = app(PaymentLifecycle::class);

        try {
            $lifecycle->restoreForRetry($order->order_number, 'nicepayments', new RetryRestoreRequest('nicepayments'));
            $this->fail('다른 결제사 행은 ProviderMismatch 여야 합니다.');
        } catch (ProviderMismatch) {
            $this->addToAssertionCount(1);
        }

        $mismatch = $lifecycle->restoreForRetry($order->order_number, 'nhnkcp', new RetryRestoreRequest('nhnkcp', -1, true, true));
        $this->assertSame('amount_mismatch', $mismatch->reason);

        $this->expectException(InvalidArgumentException::class);
        $lifecycle->restoreForRetry($order->order_number, 'nhnkcp', new RetryRestoreRequest('kcp'));
    }

    // ───────────────────────────────────────────────
    // 결제 완료, 실패, 창 닫힘, 가상계좌
    // ───────────────────────────────────────────────

    /**
     * 결제 전 행은 결제사 id 가 달라도 완료 처리하고, 인수를 켜면 같은 트랜잭션에서 결제사 id 와 에스크로 여부를 쓴다.
     *
     * @scenario case=complete_payment_takes_over_pre_payment_row
     *
     * @effects complete_payment_takes_over_pre_payment_row_provider
     *
     * @return void 반환값 없음
     */
    public function test_complete_payment_takes_over_pre_payment_row(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'tosspayments']);

        $outcome = app(PaymentLifecycle::class)->completePayment($order->order_number, 'nhnkcp', new PaymentCompletion(
            transactionId: 'T-1',
            paymentMeta: ['res_cd' => '0000'],
            isEscrow: true,
            takeOverProvider: true,
        ));

        $this->assertTrue($outcome->applied);
        $payment = $order->payment->fresh();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('nhnkcp', $payment->pg_provider);
        $this->assertTrue((bool) $payment->is_escrow);
        $this->assertSame(['res_cd' => '0000'], $payment->payment_meta);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        $this->assertTrue(app(PaymentLookup::class)->isTransactionPaid('nhnkcp', 'T-1'));
        $this->assertFalse(app(PaymentLookup::class)->isTransactionPaid('tosspayments', 'T-1'));

        $again = app(PaymentLifecycle::class)->completePayment($order->order_number, 'nhnkcp', new PaymentCompletion(transactionId: 'T-1'));
        $this->assertFalse($again->applied);
        $this->assertSame('ignored', $again->reason);
    }

    /**
     * 인수를 끄면 결제사 id 를 쓰지 않고, Closure meta 는 현재 meta 로 계산한다. 판정 클로저가 사유를 돌려주면 처리하지 않는다.
     *
     * @scenario case=complete_payment_without_takeover_and_with_guard
     *
     * @effects complete_payment_honours_takeover_flag_and_guard
     *
     * @return void 반환값 없음
     */
    public function test_complete_payment_without_takeover_and_with_guard(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'tosspayments']);
        $lifecycle = app(PaymentLifecycle::class);

        $blocked = $lifecycle->completePayment($order->order_number, 'nhnkcp', new PaymentCompletion(transactionId: 'T-2'), null,
            fn ($lockedOrder, $lockedPayment) => $lockedPayment?->paymentStatus === PaymentStatusEnum::READY ? 'custom_reason' : null);
        $this->assertSame('custom_reason', $blocked->reason);
        $this->assertSame(PaymentStatusEnum::READY, $order->payment->fresh()->payment_status);

        $lifecycle->completePayment($order->order_number, 'nhnkcp', new PaymentCompletion(
            transactionId: 'T-2',
            paymentMeta: fn (array $current) => array_merge($current, ['deposit' => 'ok']),
        ));
        $payment = $order->payment->fresh();
        $this->assertSame('tosspayments', $payment->pg_provider);
        $this->assertSame(['mid' => 'm1', 'deposit' => 'ok'], $payment->payment_meta);
    }

    /**
     * 다른 결제사가 결제한 행에 쓰면 ProviderMismatch, 없는 주문이면 PaymentNotFound 다.
     *
     * @scenario case=provider_mismatch_and_not_found
     *
     * @effects payment_write_rejects_other_provider_settled_row
     *
     * @return void 반환값 없음
     */
    public function test_provider_mismatch_and_not_found(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'tosspayments', 'payment_status' => PaymentStatusEnum::PAID, 'transaction_id' => 'TS-1', 'paid_at' => now()], ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $lifecycle = app(PaymentLifecycle::class);

        foreach ([
            fn () => $lifecycle->mergePaymentMeta($order->order_number, 'nhnkcp', ['x' => 1]),
            fn () => $lifecycle->recordPaymentFailure($order->order_number, 'nhnkcp', new PaymentFailure(PaymentStatusEnum::FAILED, fn ($m) => $m)),
            fn () => $lifecycle->failPayment($order->order_number, 'nhnkcp', 'E', 'e'),
        ] as $call) {
            try {
                $call();
                $this->fail('ProviderMismatch 여야 합니다.');
            } catch (ProviderMismatch) {
                $this->addToAssertionCount(1);
            }
        }

        // 다른 결제사 결제 후 행은 주문 단건 조회에서도 보이지 않는다
        $this->assertNull(app(OrderAccess::class)->findOrderByNumber($order->order_number, 'nhnkcp')?->payment);
        $this->assertSame('TS-1', app(OrderAccess::class)->findOrderByNumber($order->order_number, 'tosspayments')?->payment?->transactionId);

        $this->expectException(PaymentNotFound::class);
        $lifecycle->failPayment('NO-SUCH-ORDER', 'nhnkcp', 'E', 'e');
    }

    /**
     * 결제사가 등록 항목의 legacy_ids 로 선언한 옛 식별자로 남은 결제 행은 그 결제사의 쓰기를 받는다 — 식별자를 바꾸기 전(nicepay)에 만든
     * 입금대기 행의 입금 통보·환불이 ProviderMismatch 로 막히던 경로. 선언하지 않은 결제사는 그대로 막힌다.
     *
     * @scenario case=provider_mismatch_and_not_found
     *
     * @effects payment_write_accepts_declared_legacy_provider_id
     *
     * @return void 반환값 없음
     */
    public function test_declared_legacy_provider_id_is_writable(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'fx-oldpay', 'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT, 'transaction_id' => 'OLD-1', 'vbank_issued_at' => now()]);
        $lifecycle = app(PaymentLifecycle::class);

        try {
            $lifecycle->mergePaymentMeta($order->order_number, 'fx-newpay', ['x' => 1]);
            $this->fail('선언 전에는 ProviderMismatch 여야 합니다.');
        } catch (ProviderMismatch) {
            $this->addToAssertionCount(1);
        }

        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', function (array $providers) {
            $providers[] = ['id' => 'fx-newpay', 'legacy_ids' => ['fx-oldpay']];

            return $providers;
        });

        $lifecycle->mergePaymentMeta($order->order_number, 'fx-newpay', ['x' => 1]);
        $this->assertSame(1, $order->payment()->first()->payment_meta['x'] ?? null);

        $this->expectException(ProviderMismatch::class);
        $lifecycle->mergePaymentMeta($order->order_number, 'fx-otherpay', ['x' => 2]);
    }

    /**
     * 창 닫힘은 결제 실패 처리 → 결제 취소 이력 → 결제사 실패 기록 순서로 남기고, 결제 전 주문이 아니면 아무것도 하지 않는다.
     *
     * @scenario case=mark_payment_window_closed_sequence
     *
     * @effects window_closure_records_fail_cancel_failure_in_order
     *
     * @return void 반환값 없음
     */
    public function test_mark_payment_window_closed_sequence(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'tosspayments']);

        $outcome = app(PaymentLifecycle::class)->markPaymentWindowClosed($order->order_number, 'nhnkcp', new WindowClosure(
            code: 'USER_CANCEL',
            message: '닫힘',
            cancelMessage: '사유',
            failure: new PaymentFailure(PaymentStatusEnum::CANCELLED, fn (array $m) => array_merge($m, ['failure_source' => 'nhnkcp'])),
        ));

        $this->assertTrue($outcome->applied);
        $fresh = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::CANCELLED, $fresh->order_status);
        $this->assertSame('USER_CANCEL', $fresh->order_meta['payment_failure_code']);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $fresh->payment->payment_status);
        $this->assertSame('nhnkcp', $fresh->payment->pg_provider);
        $this->assertSame('사유', $fresh->payment->cancel_history[0]['cancel_message']);
        $this->assertSame('nhnkcp', $fresh->payment->payment_meta['failure_source']);

        $ignored = app(PaymentLifecycle::class)->markPaymentWindowClosed($order->order_number, 'nhnkcp', new WindowClosure('USER_CANCEL', '닫힘'));
        $this->assertSame('order_not_payable', $ignored->reason);
    }

    /**
     * 가상계좌 발급은 입금 대기로 바꾸고 발급 정보를 쓰며 meta 를 통째로 교체한다. 결제 행 실패 기록은 FAILED, CANCELLED 만 받는다.
     *
     * @scenario case=vbank_issuance_and_failure_status_guard
     *
     * @effects vbank_issuance_waits_deposit_and_replaces_meta
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issuance_and_failure_status_guard(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'tosspayments', 'payment_meta' => ['old' => 1]]);

        $outcome = app(PaymentLifecycle::class)->onVbankAccountIssued($order->order_number, 'nhnkcp', new VbankIssuance(
            vbankIssuedAt: now(),
            transactionId: 'VB-1',
            vbankName: '국민',
            vbankNumber: '123',
            paymentMeta: ['tno' => 'VB-1'],
        ));

        $this->assertTrue($outcome->applied);
        $payment = $order->payment->fresh();
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertSame('nhnkcp', $payment->pg_provider);
        $this->assertSame(['tno' => 'VB-1'], $payment->payment_meta);
        $this->assertSame('123', $outcome->order?->payment?->vbankNumber);

        $this->expectException(InvalidArgumentException::class);
        app(PaymentLifecycle::class)->recordPaymentFailure($order->order_number, 'nhnkcp', new PaymentFailure(PaymentStatusEnum::PAID, fn ($m) => $m));
    }

    // ───────────────────────────────────────────────
    // meta 쓰기
    // ───────────────────────────────────────────────

    /**
     * 부가 정보 컬럼은 허용 목록만 받고, meta 병합과 한 번에 쓴다. 상태 컬럼은 거부한다.
     *
     * @scenario case=record_pg_attributes_allow_list
     *
     * @effects pg_attributes_accept_allow_list_only
     *
     * @return void 반환값 없음
     */
    public function test_record_pg_attributes_allow_list(): void
    {
        $order = $this->makeOrder();

        $record = app(PaymentLifecycle::class)->recordPgAttributes($order->order_number, 'nhnkcp', ['embedded_pg_provider' => 'kakaopay', 'card_name' => '신한'], ['close' => 'x']);
        $this->assertSame('kakaopay', $record->embeddedPgProvider);
        $this->assertSame(['mid' => 'm1', 'close' => 'x'], $order->payment->fresh()->payment_meta);

        foreach (['payment_status', 'pg_provider', 'paid_at', 'order_id', 'paid_amount_local'] as $column) {
            try {
                app(PaymentLifecycle::class)->recordPgAttributes($order->order_number, 'nhnkcp', [$column => 'x']);
                $this->fail("{$column} 는 거부해야 합니다.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * meta 병합은 잠금 아래 최상위 키로 합치고, 유니코드 비이스케이프 인코딩은 원문 바이트를 그대로 남긴다.
     * 변형 클로저가 null 을 돌려주면 저장하지 않는다.
     *
     * @scenario case=merge_and_mutate_payment_meta_under_lock
     *
     * @effects payment_meta_merge_and_mutate_under_lock
     *
     * @return void 반환값 없음
     */
    public function test_merge_and_mutate_payment_meta_under_lock(): void
    {
        $order = $this->makeOrder();
        $lifecycle = app(PaymentLifecycle::class);
        $table = (new OrderPayment)->getTable();

        $lifecycle->mergePaymentMeta($order->order_number, 'nhnkcp', ['courier' => '한진']);
        $this->assertStringContainsString('\\ud55c\\uc9c4', (string) DB::table($table)->where('order_id', $order->id)->value('payment_meta'));

        $lifecycle->mergePaymentMeta($order->order_number, 'nhnkcp', ['courier' => '한진'], MetaEncoding::UnescapedUnicode);
        $this->assertSame('{"mid":"m1","courier":"한진"}', DB::table($table)->where('order_id', $order->id)->value('payment_meta'));

        $saved = $lifecycle->mutatePaymentMetaWithLock($order->order_number, 'nhnkcp', function (array $meta, $payment, $orderRecord) {
            $meta['history'][] = $orderRecord->orderNumber.':'.$payment->id;

            return $meta;
        });
        $this->assertCount(1, $saved['history']);

        $this->assertNull($lifecycle->mutatePaymentMetaWithLock($order->order_number, 'nhnkcp', fn () => null));
        $this->assertCount(1, $order->payment->fresh()->payment_meta['history']);
    }

    /**
     * 주문 meta 는 자기 namespace 만 읽고 쓰며, 기존 값이 없으면 클로저가 null 을 받는다. 익명화 정리는 수정 시각을 바꾸지 않는다.
     *
     * @scenario case=order_meta_namespace_and_scrub
     *
     * @effects order_meta_own_namespace_and_scrub_keeps_updated_at
     *
     * @return void 반환값 없음
     */
    public function test_order_meta_namespace_and_scrub(): void
    {
        $order = $this->makeOrder(['payment_meta' => ['escrow_delivery' => ['recv_name' => '홍길동', 'deli_numb' => '1']]]);
        $lifecycle = app(PaymentLifecycle::class);

        $seen = 'unset';
        $lifecycle->mutateOrderMetaWithLock($order->order_number, 'nhnkcp', 'nhnkcp_cbt', function (?array $existing) use (&$seen) {
            $seen = $existing;

            return ['status' => 'auto_refunded'];
        });
        $this->assertNull($seen);
        $this->assertSame(['status' => 'auto_refunded'], app(OrderAccess::class)->getOrderMeta($order->order_number, 'nhnkcp', 'nhnkcp_cbt'));

        try {
            $lifecycle->mutateOrderMetaWithLock($order->order_number, 'nhnkcp', 'payment_failure_code', fn () => []);
            $this->fail('자기 접두가 아닌 키는 거부해야 합니다.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $table = (new OrderPayment)->getTable();
        DB::table($table)->where('order_id', $order->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->assertSame(1, $lifecycle->scrubPaymentMetaKeys($order->id, 'nhnkcp', 'escrow_delivery', ['recv_name']));
        $row = DB::table($table)->where('order_id', $order->id)->first();
        $this->assertSame('2026-01-01 00:00:00', $row->updated_at);
        $this->assertSame(['recv_name' => null, 'deli_numb' => '1'], json_decode($row->payment_meta, true)['escrow_delivery']);
    }

    // ───────────────────────────────────────────────
    // 조회
    // ───────────────────────────────────────────────

    /**
     * 결제 행 조회는 자기 결제사 행만 돌려주고, 주문번호 단건 조회(거래번호 조건 없음)는 결제 전 행도 보인다.
     *
     * @scenario case=lookup_provider_scope
     *
     * @effects payment_lookup_returns_own_provider_rows
     *
     * @return void 반환값 없음
     */
    public function test_lookup_provider_scope(): void
    {
        $preOrder = $this->makeOrder(['pg_provider' => 'tosspayments']);
        $paid = $this->makeOrder(['pg_provider' => 'nhnkcp', 'payment_status' => PaymentStatusEnum::PAID, 'transaction_id' => 'K-1', 'paid_at' => now(), 'is_escrow' => true], ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $lookup = app(PaymentLookup::class);

        $this->assertNotNull($lookup->findPaymentByOrderNumber('nhnkcp', $preOrder->order_number));
        $this->assertNull($lookup->findPaymentByOrderNumber('nhnkcp', $preOrder->order_number, new PaymentFilters(hasTransactionId: true)));
        $this->assertSame('K-1', $lookup->findPaymentByTransactionId('nhnkcp', 'K-1')?->transactionId);
        $this->assertNull($lookup->findPaymentByTransactionId('kginicis', 'K-1'));
        $this->assertSame($paid->order_number, $lookup->findPaymentByOrderNumber('nhnkcp', $paid->order_number, new PaymentFilters(escrow: true, hasTransactionId: true, status: PaymentStatusEnum::PAID))?->orderNumber);
        $this->assertCount(1, $lookup->listEscrowPayments('nhnkcp', $paid->order_number));

        $listed = collect($lookup->listPaymentsByProvider(['nhnkcp', 'kcp_legacy'], now()->subMonths(6)))->pluck('orderNumber')->all();
        $this->assertContains($paid->order_number, $listed);
        $this->assertNotContains($preOrder->order_number, $listed);
    }

    /**
     * 영수증 조회는 회원 본인, 비회원 토큰, 결제사가 확인한 비회원을 구분한다.
     *
     * @scenario case=find_receipt_payment_requesters
     *
     * @effects receipt_lookup_honours_requester_ownership
     *
     * @return void 반환값 없음
     */
    public function test_find_receipt_payment_requesters(): void
    {
        $member = $this->makeOrder(['transaction_id' => 'R-1']);
        $guest = $this->makeOrder(['transaction_id' => 'R-2'], ['user_id' => null]);
        $lookup = app(PaymentLookup::class);

        $this->assertNotNull($lookup->findReceiptPayment('nhnkcp', $member->order_number, ReceiptRequester::member((int) $member->user_id)));
        $this->assertNull($lookup->findReceiptPayment('nhnkcp', $member->order_number, ReceiptRequester::member((int) $member->user_id + 999)));
        $this->assertNull($lookup->findReceiptPayment('nhnkcp', $member->order_number, ReceiptRequester::guestVerifiedByPg()));
        $this->assertNotNull($lookup->findReceiptPayment('nhnkcp', $guest->order_number, ReceiptRequester::guestVerifiedByPg()));

        $token = app(OrderAccess::class)->issueGuestOrderToken($guest->order_number)['token'] ?? null;
        $this->assertTrue(app(OrderAccess::class)->verifyGuestOrderToken($token, $guest->order_number));
        $this->assertNotNull($lookup->findReceiptPayment('nhnkcp', $guest->order_number, ReceiptRequester::guestToken($token)));
        $this->assertNull($lookup->findReceiptPayment('nhnkcp', $guest->order_number, ReceiptRequester::guestToken('bad')));
        $this->assertNull(app(OrderAccess::class)->issueGuestOrderToken($member->order_number));
    }

    /**
     * 주문 DTO 는 주문자, 결제 실패 정보, 활성 상품 여부를 싣고, 과세 구분과 청구액을 계산한다. 환율이 없으면 공개 예외를 던진다.
     *
     * @scenario case=order_record_tax_split_and_charge
     *
     * @effects order_record_tax_split_and_charge_match_services
     *
     * @return void 반환값 없음
     */
    public function test_order_record_tax_split_and_charge(): void
    {
        $order = $this->makeOrder();
        OrderOptionFactory::new()->forOrder($order)->create(['option_status' => OrderStatusEnum::PENDING_ORDER]);
        OrderAddressFactory::new()->forOrder($order)->shipping()->create(['orderer_email' => 'buyer@example.com', 'recipient_name' => '수령인']);
        $access = app(OrderAccess::class);
        $lookup = app(PaymentLookup::class);

        $record = $access->findOrderByNumber($order->order_number, 'nhnkcp');
        $this->assertSame('buyer@example.com', $record?->ordererEmail);
        $this->assertTrue($record?->hasActiveOptions);
        $this->assertSame('수령인', $access->getShippingAddress($order->order_number)?->recipientName);
        $this->assertNull($access->isOrderWithinScope('NO-SUCH', 'sirsoft-ecommerce.orders.read'));
        $this->assertSame($order->id, $access->findOrderModel($order->order_number, 'nhnkcp')?->id);

        $split = $lookup->pgTaxSplit($order->order_number);
        $this->assertSame((float) $order->pgTaxFreeAmount(), $split->taxFree);
        $this->assertSame((float) $order->pgTaxableAmount(), $split->taxable);

        $charge = $lookup->resolveChargeAmount($order->order_number);
        $this->assertSame(app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order), $charge->minorUnitAmount);

        DB::table($order->getTable())->where('id', $order->id)->update(['currency_snapshot' => json_encode(['base_currency' => 'KRW', 'order_currency' => 'ZZZ', 'exchange_rates' => []])]);
        $this->expectException(ChargeNotResolvable::class);
        $lookup->resolveChargeAmount($order->order_number);
    }

    /**
     * 결제 행 요약 목록은 전체 결제 행 목록과 범위와 조건이 같고, 세 칸(주문번호, 거래번호, 결제 meta)이 같은 값이다.
     *
     * 관리자 주문 목록의 테스트 결제 배지가 전체 결제 행 대신 이 요약을 써도 같은 배지 맵이 나와야 한다.
     *
     * @scenario case=payment_briefs_match_full_listing
     *
     * @effects payment_briefs_match_full_listing_columns
     *
     * @return void 반환값 없음
     */
    public function test_payment_briefs_match_full_listing(): void
    {
        $this->makeOrder(['transaction_id' => 'BRIEF-1', 'payment_meta' => ['is_test_mode' => true, 'pg_raw_response' => ['mid' => 'INIpayTest']]]);
        $this->makeOrder(['transaction_id' => 'BRIEF-2', 'payment_meta' => null]);
        $this->makeOrder(['transaction_id' => 'BRIEF-3', 'payment_meta' => ['is_test_mode' => false]]);
        $this->makeOrder(['pg_provider' => 'tosspayments', 'transaction_id' => 'BRIEF-OTHER', 'payment_meta' => ['is_test_mode' => true]]);
        $lookup = app(PaymentLookup::class);
        $since = now()->subMonths(6);

        foreach ([false, true] as $requireMeta) {
            $full = array_map(
                fn ($record) => [$record->orderNumber, $record->transactionId, $record->paymentMeta],
                $lookup->listPaymentsByProvider(['nhnkcp'], $since, $requireMeta),
            );
            $briefs = array_map(
                fn (PaymentBrief $brief) => [$brief->orderNumber, $brief->transactionId, $brief->paymentMeta],
                $lookup->listPaymentBriefsByProvider(['nhnkcp'], $since, $requireMeta),
            );

            sort($full);
            sort($briefs);
            $this->assertSame($full, $briefs, $requireMeta ? 'meta 있는 행만' : '전체');
            $this->assertCount($requireMeta ? 2 : 3, $briefs);
        }
    }

    /**
     * 구매자 연락처는 결제사 범위 없이 칸마다 결제 행 구매자 값, 비어 있으면 배송지 주문자 값이다. 없는 주문은 null.
     *
     * @scenario case=buyer_contact_falls_back_per_field
     *
     * @effects buyer_contact_prefers_payment_then_orderer_per_field
     *
     * @return void 반환값 없음
     */
    public function test_buyer_contact_falls_back_per_field(): void
    {
        $order = $this->makeOrder(['pg_provider' => 'manual', 'buyer_name' => '결제 구매자', 'buyer_email' => null, 'buyer_phone' => null]);
        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_name' => '주문자', 'orderer_email' => 'orderer@example.com', 'orderer_phone' => '01012341234',
        ]);

        $contact = app(OrderAccess::class)->findBuyerContact($order->order_number);

        $this->assertSame('결제 구매자', $contact?->name);
        $this->assertSame('orderer@example.com', $contact?->email);
        $this->assertSame('01012341234', $contact?->phone);
        $this->assertNull(app(OrderAccess::class)->findBuyerContact('NO-SUCH-ORDER'));
    }

    /**
     * 주문과 클레임 결제 DTO 는 계약 구현이 넘긴 값만 읽고 조회하지 않는다. 계약 조회 결과(활성 상품 여부, 부모 주문번호)는 종전과 같다.
     *
     * @scenario case=contract_dtos_do_not_query
     *
     * @effects contract_dtos_read_only_given_values
     *
     * @return void 반환값 없음
     */
    public function test_contract_dtos_do_not_query(): void
    {
        $order = $this->makeOrder();
        OrderOptionFactory::new()->forOrder($order)->create(['option_status' => OrderStatusEnum::CANCELLED]);
        $access = app(OrderAccess::class);

        $this->assertFalse($access->findOrderByNumber($order->order_number, 'nhnkcp')?->hasActiveOptions, '취소된 상품만 있으면 false');

        $fresh = Order::query()->whereKey($order->id)->first();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $record = OrderRecord::fromModel($fresh, null, true, null);
        $claimPayment = new ClaimPayment;
        $claimPayment->forceFill(['payment_number' => 'CP-DTO-1', 'order_id' => $order->id, 'amount' => 0, 'refunded_amount' => 0, 'payment_status' => ClaimPaymentStatusEnum::PENDING]);
        $claimRecord = ClaimPaymentRecord::fromModel($claimPayment, 'ORD-DTO-1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'DTO 는 조회하지 않는다');
        $this->assertTrue($record->hasActiveOptions);
        $this->assertNull($record->ordererName);
        $this->assertSame('ORD-DTO-1', $claimRecord->orderNumber);
    }

    /**
     * 공개 계약 예외 메시지는 언어 파일 문구다(키가 그대로 나오지 않고, 로케일을 따른다).
     *
     * @scenario case=contract_exception_messages_translated
     *
     * @effects contract_exception_messages_come_from_language_files
     *
     * @return void 반환값 없음
     */
    public function test_contract_exception_messages_are_translated(): void
    {
        app()->setLocale('en');
        $this->assertSame('Payment belongs to provider [kginicis], not [nhnkcp].', ProviderMismatch::between('kginicis', 'nhnkcp')->getMessage());
        $this->assertSame('Order not found: ORD-X', PaymentNotFound::forOrder('ORD-X')->getMessage());

        app()->setLocale('ko');
        $this->assertSame('주문을 찾을 수 없습니다: ORD-X', PaymentNotFound::forOrder('ORD-X')->getMessage());
        $this->assertStringNotContainsString('payment_contract', InvalidPaymentTransition::because('ORD-X', InvalidPaymentTransition::PAID_HISTORY)->getMessage());

        try {
            app(PaymentLookup::class)->isTransactionPaid([], 'T-1');
            $this->fail('결제사 id 가 비면 예외여야 합니다');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('결제사 id 가 필요합니다.', $e->getMessage());
        }
    }

    /**
     * 훅 인자 결제 행을 조회 없이 DTO 로 바꾸고, 판정 값은 Model 판정 메서드와 같다.
     *
     * @scenario case=describe_payment_matches_model_judgements
     *
     * @effects describe_payment_mirrors_model_judgements
     *
     * @return void 반환값 없음
     */
    public function test_describe_payment_matches_model_judgements(): void
    {
        $payment = $this->makeOrder(['payment_status' => PaymentStatusEnum::PAID, 'transaction_id' => 'D-1', 'paid_at' => now()])->payment;

        $record = app(PaymentLookup::class)->describePayment($payment);
        $this->assertSame($payment->isPaid(), $record->isPaid);
        $this->assertSame($payment->isVirtualAccount(), $record->isVirtualAccount);
        $this->assertSame((float) $payment->getCancellableAmount(), $record->cancellableAmount);
        $this->assertSame('D-1', $record->transactionId);
    }
}
