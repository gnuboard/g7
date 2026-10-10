<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http;

use App\Extension\HookManager;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExecuteRefundRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 결제수단별 취소·환불 경로의 돈·재고 회귀 잠금 (#599 후속 §7-2)
 *
 * 이 파일이 잠그는 셋은 전수조사에서 **돈이나 재고가 실제로 어긋나는** 결함으로 분류된 것이다:
 *
 * - **P2** PG 를 부르지 않았는데 환불완료가 찍힌다 — 고객 카드는 그대로인데 장부만 환불완료
 * - **P3** 환불수단을 결제행과 어긋나게 실으면 돈이 안 나가고 완료가 찍힌다(양방향)
 * - **P4** 취소된 주문이 뒤늦은 입금으로 되살아나 재고·마일리지가 재차감된다
 * - **P14** 결제 플러그인을 지우면 그 수단의 결제완료 주문을 영영 취소할 수 없다
 */
class PaymentMethodRefundPathTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
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
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제수단을 갈아 끼운 결제완료 주문을 만듭니다.
     *
     * @param  string  $method  결제수단 ID
     * @param  string  $provider  PG 제공자 ID (비-PG 수단은 빈 문자열)
     * @param  int  $quantity  주문 수량
     * @return Order 주문
     */
    protected function orderPaidWith(string $method, string $provider = 'testpg', int $quantity = 1): Order
    {
        $order = $this->paidClaimOrder(29000, $quantity);
        $order->payment->forceFill([
            'payment_method' => $method,
            'pg_provider' => $provider,
        ])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    // ================================================================
    // P6 — 부분취소를 지원하지 않는 결제는 사전에 막는다
    // ================================================================

    /**
     * PG 가 부분취소를 지원하지 않는다고 선언하면 **접수 단계에서** 거절한다.
     *
     * NICE 가상계좌처럼 원천적으로 전액취소만 되는 조합이 있다. 막지 않으면 운영자는 수량을
     * 줄여 접수하고 원인 없는 PG 거절을 받는다 — 그때는 이미 신청서가 만들어진 뒤다. 관리자
     * 화면이 경고 배너를 띄우지만 화면만 막으면 API 요청은 그대로 통과한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects partial_cancel_is_rejected_when_the_pg_declares_no_support
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects full_cancel_passes_even_when_partial_cancel_is_unsupported
     *
     * @return void 반환값 없음
     */
    public function test_a_partial_cancel_is_rejected_when_the_pg_declares_no_support(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.supports_partial_cancel', fn () => false);

        // 수량 2 중 1개만 취소한다 — 전량이면 전체취소로 승격돼 이 가드를 지나지 않는다.
        $order = $this->orderPaidWith('vbank', 'testpg', 2);
        $option = $order->options->first();

        $threw = false;

        try {
            app(OrderRefundRequestService::class)->request($order, [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'requested_by' => $order->user_id,
            ]);
        } catch (OrderClaimException $e) {
            $threw = true;
            $this->assertSame(
                __('sirsoft-ecommerce::exceptions.partial_cancel_not_supported'),
                $e->getMessage(),
                '거절 사유가 부분취소 미지원이어야 운영자가 무엇을 해야 하는지 안다.',
            );
        }

        $this->assertTrue($threw, '부분취소 미지원 결제의 부분 접수가 통과했다 (P6)');
    }

    /**
     * 같은 결제라도 **전액취소**는 통과한다 — 제약은 부분취소에만 걸린다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects full_cancel_passes_even_when_partial_cancel_is_unsupported
     *
     * @return void 반환값 없음
     */
    public function test_a_full_cancel_passes_even_when_partial_cancel_is_unsupported(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.supports_partial_cancel', fn () => false);
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TXN',
            'error_code' => null,
            'error_message' => null,
        ]);

        $order = $this->orderPaidWith('vbank');
        $option = $order->options->first();

        $outcome = app(OrderRefundRequestService::class)->request($order, [
            'items' => [['order_option_id' => $option->id, 'quantity' => (int) $option->quantity]],
            'reason_code' => 'changed_mind',
            'requested_by' => $order->user_id,
        ]);

        $this->assertNotNull($outcome['refund']);
    }

    // ================================================================
    // P14 — PG 리스너 부재 시 계좌 환불 폴백
    // ================================================================

    /**
     * 결제 플러그인이 제거된 뒤의 카드 주문은 계좌 환불로 떨어진다.
     *
     * `payment.refund` 는 필터 훅이라 구독자가 없으면 기본값(`success=false`)이 그대로 돌아온다 —
     * 실패와 미등록을 결과만으로 구분할 수 없어 코어는 PG 경로로 들어갔다가 예외로 롤백했고,
     * 운영자는 **그 주문의 결제완료 건을 영영 취소할 수 없었다**.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects missing_pg_listener_falls_back_to_bank_refund
     *
     * @return void 반환값 없음
     */
    public function test_a_card_order_falls_back_to_bank_refund_when_no_pg_listener_is_registered(): void
    {
        $order = $this->orderPaidWith('card');

        $this->assertSame(
            RefundMethodEnum::BANK,
            app(RefundExecutionService::class)->determineRefundMethod($order),
            'PG 리스너가 없으면 계좌 환불로 떨어져 운영자 수동 처리 경로가 살아 있어야 한다 (P14)',
        );
    }

    /**
     * 리스너가 있으면 종전대로 PG 환불이다(비회귀).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects registered_pg_listener_keeps_pg_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_a_card_order_keeps_pg_refund_when_a_listener_is_registered(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn ($d) => $d);
        $order = $this->orderPaidWith('card');

        $this->assertSame(
            RefundMethodEnum::PG,
            app(RefundExecutionService::class)->determineRefundMethod($order),
        );
    }

    // ================================================================
    // P4 — 취소된 주문은 되살아나지 않는다
    // ================================================================

    /**
     * 취소된 주문에 결제완료를 시도해도 상태가 바뀌지 않는다.
     *
     * 가상계좌 입금통보는 고객이 취소한 뒤에 입금해도 도착한다. 막지 않으면 `CANCELLED` 주문이
     * `PAYMENT_COMPLETE` 로 되살아나 **재고와 마일리지가 다시 차감되고**, 이미 복원된 쿠폰이
     * 다시 소진된다. 예외를 던지지 않고 조용히 되돌리는 이유는 PG 콜백에 500 을 주면 재시도
     * 폭주가 나기 때문이다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects cancelled_order_is_not_revived_by_a_late_deposit
     *
     * @return void 반환값 없음
     */
    public function test_a_cancelled_order_is_not_revived_by_a_late_payment_completion(): void
    {
        $order = $this->paidClaimOrder();
        $order->forceFill(['order_status' => OrderStatusEnum::CANCELLED])->save();

        $result = app(OrderProcessingService::class)->completePayment($order->fresh(), []);

        $this->assertSame(
            OrderStatusEnum::CANCELLED,
            $result->order_status,
            '취소된 주문은 결제완료로 되살아나면 안 된다 (P4)',
        );
    }

    // ================================================================
    // P9 — 사람이 송금하는 환불은 계좌가 필수
    // ================================================================

    /**
     * 무통장입금 주문의 취소는 환불 계좌를 요구한다.
     *
     * 예전에는 입금완료 가상계좌만 요구해, 무통장 주문을 취소하면 **송금 대상 계좌가 없는 채로**
     * 환불이 `APPROVED` 에 앉았다. 운영자는 어디로 보내야 할지 모르는 환불 건을 떠안았고,
     * 화면에도 오류에도 그 사실이 드러나지 않았다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects bank_transfer_cancel_requires_a_refund_account
     *
     * @return void 반환값 없음
     */
    public function test_a_bank_transfer_cancellation_requires_a_refund_account(): void
    {
        $order = $this->orderPaidWith('dbank', '');
        $order->payment->forceFill([
            'refund_bank_code' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ])->save();

        $request = new CancelOrderRequest;
        $reflection = new \ReflectionMethod($request, 'requiresRefundBank');
        $reflection->setAccessible(true);

        $this->assertTrue(
            $reflection->invoke($request, $order->fresh('payment')->payment),
            '사람이 송금해야 하는 수단은 입금 상태와 무관하게 계좌가 필수다 (P9)',
        );
    }

    /**
     * 카드 결제는 환불 계좌를 요구하지 않는다(비회귀).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects card_cancel_does_not_require_a_refund_account
     *
     * @return void 반환값 없음
     */
    public function test_a_card_cancellation_does_not_require_a_refund_account(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn ($d) => $d);
        $order = $this->orderPaidWith('card');

        $request = new CancelOrderRequest;
        $reflection = new \ReflectionMethod($request, 'requiresRefundBank');
        $reflection->setAccessible(true);

        $this->assertFalse($reflection->invoke($request, $order->fresh('payment')->payment));
    }

    /**
     * 입금 완료된 가상계좌는 PG 가 환불받을 계좌를 요구한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects settled_virtual_account_requires_a_refund_account
     *
     * @return void 반환값 없음
     */
    public function test_a_settled_virtual_account_requires_a_refund_account(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn ($d) => $d);
        $order = $this->orderPaidWith('vbank');
        $order->payment->forceFill([
            'payment_status' => PaymentStatusEnum::PAID,
            'refund_bank_code' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ])->save();

        $request = new CancelOrderRequest;
        $reflection = new \ReflectionMethod($request, 'requiresRefundBank');
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke($request, $order->fresh('payment')->payment));
    }

    // ================================================================
    // P2 — PG 미호출이면 환불완료를 찍지 않는다
    // ================================================================

    /**
     * 돈이 실제로 나가지 않은 환불은 `APPROVED` 에 머문다.
     *
     * "PG 없이 장부만 취소" 경로가 있던 시절에는 **고객 카드는 그대로인데 장부만 환불완료**인
     * 상태가 만들어졌다. 그 경로는 없앴고, 사람이 송금해야 하는 수단은 접수에서 멈춘다 —
     * 승인은 운영자의 검토가, 완료는 운영자의 [환불하기]가 찍는다(P16 이 前D4 를 대체).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects skipping_pg_never_stamps_refund_completed
     *
     * @return void 반환값 없음
     */
    public function test_a_refund_awaiting_a_human_transfer_is_never_stamped_completed(): void
    {
        $order = $this->orderPaidWith('dbank');

        $result = app(OrderCancellationService::class)
            ->cancelOrder(order: $order);

        $this->assertNotNull($result->orderRefund);
        $this->assertSame(RefundStatusEnum::REQUESTED, $result->orderRefund->refund_status);
        $this->assertNull($result->orderRefund->refunded_at);

        // 취소신청서는 환불이 집행된 순간에만 생긴다 — 아직 없다(D3·D5).
        $this->assertNull($result->orderCancel);
    }

    /**
     * 바깥에서 이미 집행된 환불은 완료로 확정한다 — 그것이 `cancelPg: false` 의 뜻이다.
     *
     * 결제 플러그인의 가상계좌 환불이 이 경로다: PG API 를 자기가 부르고 코어에는 장부만
     * 맡긴다. 돈은 이미 나갔으므로 승인에서 멈추면 운영자에게 "송금하세요" 로 보이고, 그대로
     * 이체하면 **이중 환불**이다. 이 경로는 PG 리스너 등록 여부와 무관하게 완료해야 한다 —
     * 리스너가 없을 때 계좌 환불로 떨어지는 폴백(P14)이 여기서는 정확히 반대로 작동한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects externally_settled_refund_completes_without_calling_pg
     *
     * @return void 반환값 없음
     */
    public function test_an_externally_settled_refund_completes_without_calling_pg(): void
    {
        $pgCalled = false;
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$pgCalled) {
            $pgCalled = true;

            return $default;
        });

        $order = $this->orderPaidWith('vbank');

        $result = app(OrderCancellationService::class)
            ->cancelOrder(order: $order, cancelPg: false);

        $this->assertFalse($pgCalled, '이미 집행된 환불에 PG 를 또 부르면 같은 거래를 두 번 취소한다.');
        $this->assertNotNull($result->orderRefund);
        $this->assertSame(RefundStatusEnum::COMPLETED, $result->orderRefund->refund_status);
        $this->assertNotNull($result->orderRefund->refunded_at);
        $this->assertNotNull($result->orderCancel, '환불이 집행됐으면 취소신청서가 생긴다(D3).');
    }

    // ================================================================
    // P3 — 환불수단이 결제행과 어긋나면 422
    // ================================================================

    /**
     * `ExecuteRefundRequest` 의 환불수단 대조검증을 돌려 오류 메시지를 돌려줍니다.
     *
     * 규칙이 `withValidator()` 의 `after` 콜백이라 라우트 파라미터(`id`)와 본문이 함께 있어야
     * 발화한다 — 실제 요청과 같은 모양으로 세워 그 콜백을 그대로 태운다.
     *
     * @param  OrderRefund  $refund  대상 환불신청서
     * @param  string  $method  본문에 실을 `refund_method`
     * @return array<int, string> `refund_method` 오류 메시지 (통과하면 빈 배열)
     */
    protected function executeRefundMethodErrors(OrderRefund $refund, string $method): array
    {
        $request = ExecuteRefundRequest::create('/execute', 'POST', ['refund_method' => $method]);
        $request->setContainer(app());
        $request->setRouteResolver(function () use ($request, $refund) {
            $route = new RoutingRoute('POST', 'refunds/{id}/execute', []);
            $route->bind($request);
            $route->setParameter('id', $refund->id);

            return $route;
        });

        $validator = ValidatorFactory::make($request->all(), $request->rules(), $request->messages());

        $withValidator = new \ReflectionMethod($request, 'withValidator');
        $withValidator->setAccessible(true);
        $withValidator->invoke($request, $validator);

        $validator->passes();

        return $validator->errors()->get('refund_method');
    }

    /**
     * 결제행과 어긋나는 환불수단을 실어 보내면 실행이 거절된다.
     *
     * 막으려는 것은 **돈이 안 나가는 `COMPLETED`** 다 — 무통장 결제에 `PG` 를 실으면 PG
     * 리스너가 그 거래를 알지 못해 실패하거나, 반대로 카드 결제에 `BANK` 를 실으면 PG 를
     * 부르지 않은 채 장부에만 환불완료가 찍힌다. 고객 카드는 그대로인데 장부는 환불된 상태다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects mismatched_refund_method_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_a_refund_method_that_contradicts_the_payment_is_rejected(): void
    {
        $order = $this->orderPaidWith('dbank', '');
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;

        $this->assertNotNull($refund);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);

        $this->assertNotEmpty(
            $this->executeRefundMethodErrors($refund, RefundMethodEnum::PG->value),
            '무통장 결제에 PG 환불을 실으면 돈이 나가지 않으므로 실행 전에 막아야 한다 (P3)',
        );
    }

    /**
     * 결제행과 맞는 환불수단은 그대로 통과한다(비회귀).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects matching_refund_method_passes_validation
     *
     * @return void 반환값 없음
     */
    public function test_a_refund_method_that_matches_the_payment_passes(): void
    {
        $order = $this->orderPaidWith('dbank', '');
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;

        $this->assertSame(
            [],
            $this->executeRefundMethodErrors($refund, RefundMethodEnum::BANK->value),
        );
    }

    /**
     * PG 리스너가 없는 주문은 **실효 수단**(BANK)으로 대조하고 결제행의 원시 판정(PG)은 거절한다.
     *
     * 대조 기준을 결제행의 `refundMethod()` 로 두면 P14 폴백 경로가 통째로 막힌다 — 신청서는
     * `BANK` 로 저장되는데 결제행은 여전히 `PG` 라고 말하므로, 화면이 저장값을 그대로 실어
     * 보내면 **언제나** 422 다. 운영자는 "계좌로 송금하세요" 안내를 읽고 실제로 송금한 뒤
     * [환불하기]에서 원인 없는 거절을 받는다.
     *
     * 되돌리면 red — 기준을 원시 판정으로 바꾸면 아래 두 단언의 방향이 정확히 뒤집힌다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects fallback_refund_method_is_validated_against_the_effective_method
     *
     * @return void 반환값 없음
     */
    public function test_a_fallback_refund_is_validated_against_the_effective_method(): void
    {
        $order = $this->orderPaidWith('card');
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;

        $this->assertNotNull($refund);
        $this->assertSame(
            RefundMethodEnum::BANK,
            $refund->refund_method,
            'PG 리스너가 없으면 신청서는 계좌 환불로 저장된다 (P14)',
        );

        $this->assertSame(
            [],
            $this->executeRefundMethodErrors($refund, RefundMethodEnum::BANK->value),
            '손으로 송금한 운영자가 완료를 찍는 경로는 살아 있어야 한다',
        );

        $this->assertNotEmpty(
            $this->executeRefundMethodErrors($refund, RefundMethodEnum::PG->value),
            '결제행의 원시 판정(PG)으로는 돈이 나가지 않는다 — 실효 수단과 다르므로 막는다',
        );
    }
}
