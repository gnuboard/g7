<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 서비스 테스트 (S5)
 *
 * 검증 축:
 * - `payment_pending` 진입 — 신청 시점 결제만 결제 대기 건(CP)을 만들고 신청서를 결제대기로 옮긴다
 * - 결제 실행 — 마일리지 FIFO 차감(claim_payment_id 멱등) / PG 결제창 파라미터(CP = order_number, 최소 화폐단위)
 * - PG 승인 — 훅 계약(success / handled / 금액 대조 / 연동 부재) 과 재시도 멱등
 * - 결제 확정 뒤 전이 — 승인 시점 결제는 항상 승인, 신청 시점 결제는 설정이 참일 때만 승인
 * - 환급 — 철회 시 전액(마일리지 복원 · PG 환급 훅 + 멱등키), 재발송지 수정 차액의 부분 환급·추가 청구
 * - 주문 합계 — 실수납 합계는 부분 환급을 뺀다
 */
class ClaimPaymentServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected ClaimPaymentService $service;

    protected OrderReturnService $returnService;

    protected OrderExchangeService $exchangeService;

    /**
     * 테스트 전제(통화·사유·서비스 등)를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(ClaimPaymentService::class);
        $this->returnService = app(OrderReturnService::class);
        $this->exchangeService = app(OrderExchangeService::class);
    }

    /**
     * 테스트가 바꾼 설정·훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 회수 배송비 3,000 원이 청구되는 반품을 접수합니다.
     *
     * @param  ClaimChargeMethodEnum  $method  청구 방식
     * @param  Order|null  $order  주문 (없으면 새로 만든다)
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function requestedReturn(ClaimChargeMethodEnum $method = ClaimChargeMethodEnum::PAY_ON_REQUEST, ?Order $order = null): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return $this->returnService->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => $method,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 왕복 배송비 6,000 원이 청구되는 교환을 접수합니다.
     *
     * @param  ClaimChargeMethodEnum  $method  청구 방식
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function requestedExchange(ClaimChargeMethodEnum $method = ClaimChargeMethodEnum::PAY_ON_REQUEST): OrderExchange
    {
        $order = $this->deliveredClaimOrder();

        return $this->exchangeService->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => $method,
            'requested_by' => $order->user_id,
        ]);
    }

    // ================================================================
    // payment_pending 진입
    // ================================================================

    /**
     * 신청 시점 결제 반품의 접수 직후 결제 대기 건이 만들어지고 신청서가 결제대기로 옮겨지는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_row_is_created_only_when_the_charge_is_positive
     *
     * @return void 반환값 없음
     */
    public function test_initiate_creates_a_pending_payment_and_moves_the_return_to_payment_pending(): void
    {
        $return = $this->requestedReturn();

        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $this->assertInstanceOf(ClaimPayment::class, $payment);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->payment_status);
        $this->assertStringStartsWith('CP', $payment->payment_number);
        $this->assertSame('return', $payment->payable_type);
        $this->assertSame($return->id, (int) $payment->payable_id);
        $this->assertSame($return->order_id, (int) $payment->order_id);
        $this->assertSame(3000.0, (float) $payment->amount);
        $this->assertSame(0.0, (float) $payment->points_amount);
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
    }

    /**
     * 환불금 차감 방식이면 결제 대기 건을 만들지 않는지 검증합니다.
     *
     * @effects claim_payment_is_not_created_for_deduct_from_refund
     *
     * @return void 반환값 없음
     */
    public function test_initiate_does_nothing_for_deduct_from_refund(): void
    {
        $return = $this->requestedReturn(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND);

        $this->assertNull($this->service->initiateForRequest($return, null));
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(0, ClaimPayment::count());
    }

    /**
     * 승인 시점 결제는 운영자가 승인하기 전까지 결제 대기 건을 만들지 않는지 검증합니다.
     *
     * @effects claim_payment_on_approval_waits_for_the_admin
     *
     * @return void 반환값 없음
     */
    public function test_initiate_does_nothing_for_pay_on_approval_until_the_admin_approves(): void
    {
        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);

        $this->assertNull($this->service->initiateForRequest($return, null));
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * 청구액이 0 원이면 결제 대기 건을 만들지 않는지 검증합니다.
     *
     * @effects claim_payment_row_is_created_only_when_the_charge_is_positive
     *
     * @return void 반환값 없음
     */
    public function test_initiate_does_nothing_when_the_charge_is_zero(): void
    {
        $order = $this->deliveredClaimOrder(returnFee: 0);
        $return = $this->returnService->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'requested_by' => $order->user_id,
        ]);

        $this->assertNull($this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value));
    }

    /**
     * 허용 목록 밖의 결제 수단을 거절하는지 검증합니다.
     *
     * @effects claim_payment_method_outside_the_allowed_list_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_initiate_rejects_a_payment_method_outside_the_allowed_list(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', [PaymentMethodEnum::CARD->value]);
        $return = $this->requestedReturn();

        $this->assertClaimException('claim_payment_method_not_allowed', fn () => $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value));
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * 승인 시점 결제가 승인 시도 때 결제 대기 건을 만드는지 검증합니다.
     *
     * @effects claim_payment_on_approval_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_request_payment_on_approval_creates_the_pending_payment_at_approval_time(): void
    {
        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);

        $adminId = $this->createAdminUser()->id;
        $payment = $this->service->requestPaymentOnApproval($return, $adminId);

        $this->assertNotNull($payment);
        $this->assertSame(3000.0, (float) $payment->getTotalAmount());
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
        // 두 번 부르면 같은 결제 대기 건을 돌려준다.
        $this->assertSame($payment->id, $this->service->requestPaymentOnApproval($return, $adminId)->id);
        $this->assertSame(1, ClaimPayment::count());
    }

    /**
     * 비회원 신청서의 승인 시점 결제 건이 마일리지 수단으로 만들어지지 않는지 검증합니다.
     *
     * @effects claim_payment_guest_never_gets_a_mileage_payment_row
     *
     * @return void 반환값 없음
     */
    public function test_request_payment_on_approval_never_creates_a_mileage_row_for_a_guest(): void
    {
        // 승인 시점 결제도 결제행을 만드는 경로다. 허용 목록의 첫 수단을 그대로 쓰면 마일리지를 앞에 둔
        // 상점에서 비회원 신청서의 결제행이 `point` 로 만들어져, 그 건은 수단을 명시하지 않는 한
        // 다시 열리지 않는다. 신청 시점 결제·차액 정산과 같은 이유로 신청자 자격을 보아야 한다.
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', [
            PaymentMethodEnum::POINT->value,
            PaymentMethodEnum::CARD->value,
        ]);

        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $return->forceFill(['requested_by' => null])->save();
        // 비회원은 **주문의 주인**으로 정한다 — `requested_by` 가 null 인 것만으로는 운영자 직권 접수와
        // 구별되지 않는다(S3-D15). 회원 주문에 직권 접수한 건은 회원 수단을 쓴다.
        $return->order->forceFill(['user_id' => null])->save();

        $payment = $this->service->requestPaymentOnApproval($return->fresh(), $this->createAdminUser()->id);

        $this->assertNotNull($payment);
        $this->assertSame(
            PaymentMethodEnum::CARD->value,
            $payment->payment_method,
            '비회원은 마일리지로 낼 수 없으므로 그가 쓸 수 있는 수단으로 결제행이 만들어져야 합니다.'
        );
        $this->assertSame(0.0, (float) $payment->points_amount);
    }

    // ================================================================
    // 마일리지 결제
    // ================================================================

    /**
     * 마일리지 결제가 FIFO 로 차감되고 결제 건이 결제 완료가 되는지 검증합니다.
     *
     * @scenario method=point, timing=on_request, outcome=approved
     *
     * @effects claim_payment_mileage_is_deducted_fifo_once_per_payment
     *
     * @return void 반환값 없음
     */
    public function test_pay_with_mileage_deducts_fifo_and_marks_the_payment_paid(): void
    {
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.payment_completed', function ($claim, $paid) use (&$fired) {
            $fired[] = [$claim->id, $paid->payment_number];
        });

        $result = $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $paid = $result['payment'];
        $this->assertFalse($result['requires_pg_payment']);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->payment_status);
        $this->assertSame(3000.0, (float) $paid->points_amount);
        $this->assertSame(0.0, (float) $paid->amount);
        $this->assertNull($paid->pg_provider);
        $this->assertNotNull($paid->paid_at);

        $use = MileageTransaction::where('claim_payment_id', $paid->id)->where('type', MileageTransactionTypeEnum::ORDER_USE->value)->first();
        $this->assertNotNull($use);
        $this->assertSame(-3000.0, (float) $use->amount);
        $this->assertSame($return->order_id, (int) $use->order_id);
        $this->assertSame([[$return->id, $paid->payment_number]], $fired);
    }

    /**
     * 마일리지 잔액이 모자라면 결제 완료로 표시하지 않고 거절하는지 검증합니다.
     *
     * @scenario method=point, timing=on_request, outcome=failed
     *
     * @effects claim_payment_mileage_insufficient_balance_leaves_the_payment_pending
     *
     * @return void 반환값 없음
     */
    public function test_pay_with_mileage_rejects_an_insufficient_balance_without_marking_paid(): void
    {
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 1000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $this->assertClaimException('claim_payment_failed', fn () => $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
        $this->assertSame(0, MileageTransaction::where('claim_payment_id', $payment->id)->count());
    }

    /**
     * 마일리지 결제에는 회원이 필요한지 검증합니다.
     *
     * @effects claim_payment_mileage_requires_a_member
     *
     * @return void 반환값 없음
     */
    public function test_pay_with_mileage_requires_a_member(): void
    {
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $this->assertClaimException('claim_point_payment_requires_member', fn () => $this->service->pay($payment, PaymentMethodEnum::POINT->value, null));
    }

    /**
     * 결제 대기가 아닌 건의 결제 시작을 거절하는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_pg_approval_is_idempotent_for_a_replayed_callback
     *
     * @return void 반환값 없음
     */
    public function test_pay_rejects_a_payment_that_is_not_pending(): void
    {
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $this->assertClaimException('claim_payment_not_pending', fn () => $this->service->pay($payment->fresh(), PaymentMethodEnum::POINT->value, (int) $return->requested_by));
    }

    // ================================================================
    // PG 결제창 파라미터 / 승인
    // ================================================================

    /**
     * 카드 결제 진입이 결제번호(CP)를 가맹점 주문번호로 싣는지 검증합니다.
     *
     * @effects claim_payment_number_is_the_merchant_order_id
     *
     * @return void 반환값 없음
     */
    public function test_pay_with_card_returns_the_pg_entry_with_the_cp_number_as_merchant_order_id(): void
    {
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $result = $this->service->pay($payment, PaymentMethodEnum::CARD->value, (int) $return->requested_by);

        $this->assertTrue($result['requires_pg_payment']);
        $this->assertSame('sirsoft-testpg', $result['pg_provider']);
        $this->assertSame('sirsoft-testpg.requestPayment', $result['pg_payment_handler']);
        $data = $result['pg_payment_data'];
        $this->assertSame($payment->payment_number, $data['order_number']);
        $this->assertSame($payment->payment_number, $data['claim_payment_number']);
        $this->assertSame(3000, $data['amount']);
        $this->assertSame('KRW', $data['currency']);
        $this->assertSame('return', $data['claim_type']);
        $this->assertSame($return->return_number, $data['claim_number']);
        $this->assertSame('홍길동', $data['customer_name']);
        $this->assertSame(PaymentMethodEnum::CARD->value, $data['payment_method']);
        $this->assertSame('testpg', $payment->fresh()->pg_provider);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 통화 스냅샷이 없는 주문의 카드 결제 진입을 거절하는지 검증합니다.
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_pay_with_card_is_rejected_when_the_order_has_no_currency_snapshot(): void
    {
        // 진입 금액과 승인 대조 금액은 같은 해석기(`resolvePaymentCharge`)를 써야 한다 — 스냅샷을
        // 못 읽으면 대조는 닫히는데 진입만 현재 상점 설정으로 미끄러지면, 결제창은 열리고 승인
        // 확정 **뒤에** 금액 불일치로 거절되어 망취소 보상이 필요해진다.
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->order->update(['currency_snapshot' => null]);

        $this->assertClaimException(
            'claim_payment_currency_unavailable',
            fn () => $this->service->pay($payment->fresh(), PaymentMethodEnum::CARD->value, (int) $return->requested_by)
        );
    }

    /**
     * 통화 스냅샷을 읽을 수 없으면 결제 행을 만들기 전에 신청 시점 결제를 거절하는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_initiate_for_request_rejects_before_creating_the_payment_row_when_the_currency_snapshot_is_unreadable(): void
    {
        // 거절이 상태 변경 **뒤로** 밀리면 접수는 이미 커밋된 뒤라, 사용자는 422 를 받는데 신청서는
        // 결제대기로 옮겨지고 낼 수 없는 결제행이 남는다 — 스스로 되돌릴 수 없는 상태가 된다.
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $return->order->update(['currency_snapshot' => null]);

        $this->assertClaimException(
            'claim_payment_currency_unavailable',
            fn () => $this->service->initiateForRequest($return->fresh(), PaymentMethodEnum::CARD->value)
        );

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(0, ClaimPayment::query()->count());
    }

    /**
     * 통화 스냅샷을 읽을 수 없으면 결제 행을 만들기 전에 승인 시점 결제를 거절하는지 검증합니다.
     *
     * @scenario method=card, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_request_payment_on_approval_rejects_before_creating_the_payment_row_when_the_currency_snapshot_is_unreadable(): void
    {
        // 결제행을 만드는 세 경로(`initiateForRequest`·`requestPaymentOnApproval`·`settleChargeDifference`)는
        // 같은 기준을 써야 한다 — 한 곳만 빠지면 그 경로가 조용한 우회로가 된다.
        $this->registerTestPgProvider();
        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $return->order->update(['currency_snapshot' => null]);

        $this->assertClaimException(
            'claim_payment_currency_unavailable',
            fn () => $this->service->requestPaymentOnApproval($return->fresh(), $this->createAdminUser()->id)
        );

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(0, ClaimPayment::query()->count());
    }

    /**
     * 통화 스냅샷을 읽을 수 없으면 결제 행을 바꾸지 않고 거절하는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_pay_leaves_the_payment_row_untouched_when_the_currency_snapshot_is_unreadable(): void
    {
        // 수단 확정 가드와 같은 규율 — 거절되는 요청이 결제행을 바꿔 놓으면 안 된다.
        $this->registerTestPgProvider();
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', [
            PaymentMethodEnum::CARD->value,
            PaymentMethodEnum::BANK->value,
        ]);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->order->update(['currency_snapshot' => null]);

        $this->assertClaimException(
            'claim_payment_currency_unavailable',
            fn () => $this->service->pay($payment->fresh(), PaymentMethodEnum::BANK->value, (int) $return->requested_by)
        );

        $this->assertSame(PaymentMethodEnum::CARD->value, $payment->fresh()->payment_method);
    }

    /**
     * 통화 스냅샷을 읽을 수 없으면 차액 정산이 쓸 수 없는 진입을 돌려주지 않고 거절하는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_settle_charge_difference_rejects_instead_of_returning_an_unusable_entry_when_the_snapshot_is_unreadable(): void
    {
        // 수정 경로가 조용히 `pg_payment_data: null` 을 200 으로 돌려주면 화면은 결제창을 열 수 없는데
        // 오류도 없다 — 진입 액션 셋이 같은 입력에 같은 결말을 내야 한다.
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->order->update(['currency_snapshot' => null]);
        $return->fresh()->update(['total_charge_amount' => 9000]);

        $this->assertClaimException(
            'claim_payment_currency_unavailable',
            fn () => $this->service->settleChargeDifference($return->fresh())
        );

        $this->assertSame('3000.00', (string) $payment->fresh()->amount);
    }

    /**
     * 통화 스냅샷을 읽을 수 없으면 결제 진입 파라미터를 비우는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_requires_a_readable_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_payment_payload_omits_the_pg_entry_when_the_currency_snapshot_is_unreadable(): void
    {
        // 신청서 상세(읽기 경로)는 예외를 던지지 않는다 — 청구는 그대로 남아 있으므로
        // `requires_pg_payment` 는 참으로 두되 진입 파라미터를 만들지 않아 결제창이 열리지 않게 한다.
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->order->update(['currency_snapshot' => null]);

        $payload = $this->service->buildPaymentPayload($payment->fresh());

        $this->assertTrue($payload['requires_pg_payment']);
        $this->assertNull($payload['pg_payment_data']);
    }

    /**
     * 결제 수단을 바꾸면 금액이 PG 칸과 마일리지 칸 사이로 옮겨지는지 검증합니다.
     *
     * @effects claim_payment_switching_the_method_moves_the_amount_between_columns
     *
     * @return void 반환값 없음
     */
    public function test_pay_switching_the_method_moves_the_amount_between_pg_and_points_columns(): void
    {
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $this->grantMileage((int) $return->requested_by, 10000);

        $result = $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $result['payment']->payment_status);
        $this->assertSame(3000.0, (float) $result['payment']->points_amount);
        $this->assertSame(0.0, (float) $result['payment']->amount);
    }

    /**
     * PG 승인이 플러그인 훅을 거쳐 결제를 확정하는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_pg_approval_goes_through_the_plugin_hook
     *
     * @return void 반환값 없음
     */
    public function test_approve_confirms_the_payment_through_the_plugin_hook(): void
    {
        $this->registerTestPgProvider();
        $captured = null;
        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 3000, 'raw_response' => ['ok' => true]], $captured);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $paid = $this->service->approvePgPayment($payment, ['paymentKey' => 'pk_1', 'orderId' => $payment->payment_number, 'amount' => 3000]);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->payment_status);
        $this->assertSame('TXN-'.$payment->payment_number, $paid->transaction_id);
        $this->assertSame(['ok' => true], $paid->raw_response);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame($payment->payment_number, $captured['payment']->payment_number);
        $this->assertSame('pk_1', $captured['callback']['paymentKey']);
    }

    /**
     * PG 실패 원문이 응답에 새지 않고 코드만 실리는지 검증합니다.
     *
     * @effects claim_payment_pg_failure_message_is_not_leaked_to_the_response
     *
     * @return void 반환값 없음
     */
    public function test_pg_failure_message_does_not_leak_the_provider_text_to_the_response(): void
    {
        // 이 예외는 비회원(비인증) 경로로도 나간다 — PG 원문은 상점 MID·내부 상태를 담을 수 있으므로
        // 응답에는 코드만 싣고 원문은 로그·컬럼에만 남긴다.
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', fn () => [
            'handled' => true, 'success' => false,
            'error_code' => 'PG_DECLINED',
            'error_message' => 'INICIS: 가맹점 MID(INIpayTest) 인증 실패 - 상점키 불일치',
            'raw_response' => null, 'transaction_id' => null, 'paid_amount' => null,
        ]);

        try {
            $this->service->approvePgPayment($payment, ['x' => 1]);
            $this->fail('결제 실패 예외가 발생해야 합니다.');
        } catch (OrderClaimException $e) {
            $params = $e->getMessageParams();
            $this->assertSame('PG_DECLINED', $params['error'] ?? null);
            $this->assertStringNotContainsString('INIpayTest', (string) ($params['error'] ?? ''));
        }
    }

    /**
     * 환급 대상을 해석할 수 없는 마일리지 환급을 환급 완료로 기록하지 않는지 검증합니다.
     *
     * @effects claim_payment_point_refund_without_an_owner_is_not_marked_refunded
     *
     * @return void 반환값 없음
     */
    public function test_a_point_refund_without_a_resolvable_owner_is_not_marked_refunded(): void
    {
        // 환급 대상을 못 찾았는데 상태만 환급 완료로 옮기면 낸 돈이 사라진 채 장부만 정산된다.
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        // 주문·신청자 어느 쪽으로도 환급 대상을 해석할 수 없는 상태를 만든다.
        $return->forceFill(['requested_by' => null])->save();
        Order::query()->whereKey($return->order_id)->update(['user_id' => null]);

        $paid = $payment->fresh();

        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPayment($paid, 1000.0, 'test'));
        $this->assertSame(0.0, (float) $paid->fresh()->refunded_amount, '환급하지 못했는데 누적액이 올라가면 안 됩니다.');
    }

    /**
     * 같은 콜백이 다시 도달해도 승인이 한 번만 일어나는지 검증합니다.
     *
     * @effects claim_payment_pg_approval_is_idempotent_for_a_replayed_callback
     *
     * @return void 반환값 없음
     */
    public function test_approve_is_idempotent_for_a_replayed_callback(): void
    {
        $this->registerTestPgProvider();
        $calls = 0;
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, $payment) use (&$calls) {
            $calls++;

            return array_merge($default, [
                'handled' => true,
                'success' => true,
                'transaction_id' => 'TXN-ONCE',
                'paid_amount' => app(ClaimPaymentService::class)->resolvePaymentCharge($payment)['minor_unit_amount'],
            ]);
        });
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $this->service->approvePgPayment($payment, []);
        $again = $this->service->approvePgPayment($payment->fresh(), []);

        $this->assertSame(1, $calls);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $again->payment_status);
        $this->assertSame(1, ClaimPayment::count());
    }

    /**
     * 승인 연동이 없으면 거절하고 결제 대기로 남기는지 검증합니다.
     *
     * @effects claim_payment_pg_approval_without_a_listener_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_approve_without_a_plugin_listener_is_rejected_and_leaves_the_payment_pending(): void
    {
        $this->registerTestPgProvider();
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $this->assertClaimException('claim_payment_provider_unavailable', fn () => $this->service->approvePgPayment($payment, []));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * PG 승인 실패가 오류를 기록하고 결제 대기로 남기는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=failed
     *
     * @effects claim_payment_pg_failure_keeps_the_payment_pending_and_records_the_error
     *
     * @return void 반환값 없음
     */
    public function test_approve_failure_records_the_pg_error_and_keeps_the_payment_pending(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(false);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $this->assertClaimException('claim_payment_failed', fn () => $this->service->approvePgPayment($payment, []));

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $fresh->payment_status);
        $this->assertSame('DECLINED', $fresh->pg_error_code);
        $this->assertSame('card declined', $fresh->pg_error_message);
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
    }

    /**
     * 승인 금액이 청구액과 다르면 거절하는지 검증합니다.
     *
     * @effects claim_payment_amount_must_match_the_frozen_charge
     *
     * @return void 반환값 없음
     */
    public function test_approve_rejects_an_amount_that_differs_from_the_charge(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 2999]);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $this->assertClaimException('claim_payment_amount_mismatch', fn () => $this->service->approvePgPayment($payment, []));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
        // 이 가짜 PG 는 망취소를 지원하지 않는다 — 승인된 돈이 결제사에 묶였으므로 결제 건에는 망취소 실패가 남는다(B4).
        // 금액 불일치 사유는 위 예외 키가 말한다.
        $this->assertSame('VOID_FAILED', $payment->fresh()->pg_error_code);
    }

    // ================================================================
    // 결제 확정 뒤 전이
    // ================================================================

    /**
     * 자동 승인이 꺼져 있으면 신청 시점 결제가 결제 뒤 결제완료에 머무는지 검증합니다.
     *
     * @effects claim_payment_completion_moves_the_claim_to_paid
     *
     * @return void 반환값 없음
     */
    public function test_pay_on_request_lands_in_paid_after_payment_unless_auto_approve_is_on(): void
    {
        // §4-2 paid 규약 — 자동 승인이 꺼져 있어도 수납이 끝난 건은 결제대기에 남지 않는다.
        // 남으면 운영자가 미수납 건과 구분할 수 없다.
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $this->assertSame(ReturnStatusEnum::PAID, $return->fresh()->return_status);
        $this->assertSame(0.0, $this->service->outstandingAmount($return->fresh()));
    }

    /**
     * 결제완료 신청서도 신청자가 철회할 수 있는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=refunded
     *
     * @effects claim_payment_completion_moves_the_claim_to_paid
     *
     * @return void 반환값 없음
     */
    public function test_paid_claims_are_still_withdrawable_by_the_requester(): void
    {
        // paid 는 승인 전이므로 철회 경계 안이다 — 전이 게이트와 isWithdrawable() 이 갈리면
        // 화면은 [철회] 를 보여 주는데 요청만 거부된다.
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $paid = $return->fresh();
        $this->assertSame(ReturnStatusEnum::PAID, $paid->return_status);
        $this->assertTrue($paid->return_status->isWithdrawable());
        $this->assertTrue($paid->return_status->canTransitionTo(ReturnStatusEnum::WITHDRAWN));
    }

    /**
     * 자동 승인이 켜져 있으면 신청 시점 결제가 결제 뒤 승인으로 옮겨지는지 검증합니다.
     *
     * @effects claim_payment_on_request_auto_approves_only_when_the_setting_is_on
     *
     * @return void 반환값 없음
     */
    public function test_pay_on_request_auto_approves_after_payment_when_the_setting_is_on(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_after_payment', true);
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        // 자동 승인이 켜져 있어도 paid 를 건너뛰지 않는다 — 도착지는 승인이되 경유지가 남는다.
        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
    }

    /**
     * 승인 시점 결제는 결제 뒤 항상 승인으로 옮겨지는지 검증합니다.
     *
     * @scenario method=point, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_on_approval_always_approves_after_payment
     *
     * @return void 반환값 없음
     */
    public function test_pay_on_approval_always_approves_after_payment(): void
    {
        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->requestPaymentOnApproval($return, $this->createAdminUser()->id);

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
    }

    /**
     * 승인 시점 카드 결제가 플러그인 훅으로 확정되는지 검증합니다.
     *
     * @scenario method=card, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_on_approval_always_approves_after_payment
     *
     * @return void 반환값 없음
     */
    public function test_pay_on_approval_confirms_a_card_payment_through_the_plugin_hook(): void
    {
        // 승인 시점 청구도 결제창을 거친다 — 청구가 언제 발행됐는지는 결제 행이 만들어지는
        // 순간만 바꾸고, 그 뒤의 PG 승인 경로는 신청 시점 결제와 같은 코드다.
        $this->registerTestPgProvider();
        $captured = null;
        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 3000, 'raw_response' => ['ok' => true]], $captured);
        $return = $this->requestedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $payment = $this->service->requestPaymentOnApproval($return, $this->createAdminUser()->id);

        $this->assertSame(PaymentMethodEnum::CARD->value, $payment->payment_method);

        $paid = $this->service->approvePgPayment($payment, [
            'paymentKey' => 'pk_1',
            'orderId' => $payment->payment_number,
            'amount' => 3000,
        ]);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->payment_status);
        $this->assertSame(
            ReturnStatusEnum::APPROVED,
            $return->fresh()->return_status,
            '승인 시점 결제는 설정과 무관하게 결제 확정 뒤 승인으로 간다.'
        );
    }

    /**
     * 교환 결제 확정이 교환 훅을 발화하고 자동 승인할 수 있는지 검증합니다.
     *
     * @effects claim_payment_completion_fires_the_claim_type_specific_hook
     *
     * @return void 반환값 없음
     */
    public function test_exchange_payment_completion_fires_the_exchange_hook_and_can_auto_approve(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_after_payment', true);
        $exchange = $this->requestedExchange();
        $this->grantMileage((int) $exchange->requested_by, 10000);
        $payment = $this->service->initiateForRequest($exchange, PaymentMethodEnum::POINT->value);
        $this->assertSame(ExchangeStatusEnum::PAYMENT_PENDING, $exchange->fresh()->exchange_status);

        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.payment_completed', function () use (&$fired) {
            $fired++;
        });

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $exchange->requested_by);

        $this->assertSame(1, $fired);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->fresh()->exchange_status);
        $this->assertSame(6000.0, (float) $payment->fresh()->points_amount);
    }

    /**
     * P5 (17차 계획 검증) — 훅 소비자(알림·활동 로그)는 신청서 상태를 읽는다.
     *
     * 훅이 전이보다 앞서면 정상 경로에서도 리스너가 보는 상태가 언제나 `payment_pending`
     * 이라 '결제완료' 를 통지하면서 결제대기라고 적게 된다. `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md`
     * 도 "옮겨지고 … 훅이 발행됩니다" 로 이 순서를 계약으로 공표한다.
     *
     * @effects claim_payment_completion_hook_sees_the_claim_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_payment_completion_hook_sees_the_claim_already_moved_to_paid(): void
    {
        $return = $this->requestedReturn();
        $this->grantMileage((int) $return->requested_by, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        $observed = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.payment_completed', function ($claim) use (&$observed) {
            $observed[] = $claim->return_status->value;
        });

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $this->assertSame([ReturnStatusEnum::PAID->value], $observed);
    }

    /**
     * 일부만 수납되면 결제대기에 머물고 결제 완료 훅을 발화하지 않는지 검증합니다.
     *
     * @effects claim_payment_partial_payment_stays_in_payment_pending
     *
     * @return void 반환값 없음
     */
    public function test_partial_payment_leaves_the_claim_in_payment_pending_without_firing_the_completion_hook(): void
    {
        $return = $this->requestedReturn();
        $userId = (int) $return->requested_by;
        $this->grantMileage($userId, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);

        // 결제 대기 건(3,000)을 만든 뒤 청구액만 5,000 으로 올린다 — 이 건을 결제해도 2,000 이 남는다.
        $return->fresh()->forceFill(['total_charge_amount' => 5000, 'claim_shipping_fee' => 5000])->save();

        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.return.payment_completed', function () use (&$fired) {
            $fired++;
        });

        $this->service->pay($payment, PaymentMethodEnum::POINT->value, $userId);

        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
        $this->assertSame(2000.0, $this->service->outstandingAmount($return->fresh()));
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
        $this->assertSame(0, $fired);
    }

    // ================================================================
    // 환급
    // ================================================================

    /**
     * 신청서 종결 환급이 마일리지를 한 번만 복원하고 결제 건을 환급 완료로 두는지 검증합니다.
     *
     * @scenario method=point, timing=on_request, outcome=refunded
     *
     * @effects claim_payment_refund_restores_mileage_once
     *
     * @return void 반환값 없음
     */
    public function test_refund_for_claim_restores_mileage_once_and_marks_the_payment_refunded(): void
    {
        $return = $this->requestedReturn();
        $userId = (int) $return->requested_by;
        $this->grantMileage($userId, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, $userId);

        $processed = $this->service->refundPaymentsForClaim($return->fresh(), 'withdrawn');
        $this->service->refundPaymentsForClaim($return->fresh(), 'withdrawn');

        $this->assertCount(1, $processed);
        $this->assertSame(ClaimPaymentStatusEnum::REFUNDED, $payment->fresh()->payment_status);
        $this->assertSame(3000.0, (float) $payment->fresh()->refunded_amount);
        $this->assertSame(1, MileageTransaction::where('claim_payment_id', $payment->id)->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->count());
        $this->assertSame(10000.0, (float) MileageTransaction::where('user_id', $userId)->sum('remaining_amount'));
    }

    /**
     * 신청서 종결 시 결제 대기 건은 환급 없이 취소되는지 검증합니다.
     *
     * @effects claim_payment_refund_cancels_a_pending_payment_without_refunding
     *
     * @return void 반환값 없음
     */
    public function test_refund_for_claim_cancels_a_pending_payment_without_refunding(): void
    {
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $processed = $this->service->refundPaymentsForClaim($return, 'withdrawn');

        $this->assertCount(1, $processed);
        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $payment->fresh()->payment_status);
    }

    /**
     * 신청서 종결 환급이 멱등키와 함께 PG 환급 훅을 부르는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=refunded
     *
     * @effects claim_payment_refund_calls_the_pg_hook_with_an_idempotency_key
     *
     * @return void 반환값 없음
     */
    public function test_refund_for_claim_calls_the_pg_refund_hook_with_an_idempotency_key(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        $calls = null;
        $this->fakeClaimPaymentRefund(true, $calls);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $this->service->approvePgPayment($payment, []);

        $this->service->refundPaymentsForClaim($return->fresh(), 'rejected');

        $this->assertCount(1, $calls);
        $this->assertSame(3000.0, $calls[0]['amount']);
        $this->assertSame('rejected', $calls[0]['reason']);
        $this->assertSame(sprintf('g7-claimpay-%d-0-300000', $payment->id), $calls[0]['key']);
        $this->assertSame(ClaimPaymentStatusEnum::REFUNDED, $payment->fresh()->payment_status);
    }

    /**
     * 실패한 환급을 다른 금액으로 재시도하면 멱등키가 바뀌는지 검증합니다.
     *
     * @effects claim_payment_refund_idempotency_key_changes_when_the_amount_changes
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_idempotency_key_changes_when_a_failed_refund_is_retried_with_another_amount(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        $calls = null;
        $this->fakeClaimPaymentRefund(false, $calls);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $this->service->approvePgPayment($payment, []);

        // 부분환급 A(1,000)가 PG 에서 거절되면 누적액은 그대로다 — 다른 금액 B(2,000)로 재시도할 때
        // 키가 같으면 계약("같은 키의 재요청은 한 번만 처리")대로 B 가 A 로 접힌다. 키 재료에
        // 이번 환급액이 들어 있어 두 시도의 키가 달라야 하고, 같은 금액의 재시도는 같은 키여야 한다.
        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPayment($payment->fresh(), 1000.0, 'first'));
        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPayment($payment->fresh(), 2000.0, 'retry'));
        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPayment($payment->fresh(), 2000.0, 'retry-same'));

        $this->assertCount(3, $calls);
        $this->assertNotSame($calls[0]['key'], $calls[1]['key']);
        $this->assertSame($calls[1]['key'], $calls[2]['key']);
        $this->assertSame(sprintf('g7-claimpay-%d-0-100000', $payment->id), $calls[0]['key']);
        $this->assertSame(sprintf('g7-claimpay-%d-0-200000', $payment->id), $calls[1]['key']);
    }

    /**
     * PG 환급 실패가 예외로 올라오고 결제 건을 결제 완료로 남기는지 검증합니다.
     *
     * @effects claim_payment_refund_failure_keeps_the_payment_paid
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_failure_is_raised_and_keeps_the_payment_paid(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        $calls = null;
        $this->fakeClaimPaymentRefund(false, $calls);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $this->service->approvePgPayment($payment, []);

        $this->assertClaimException('claim_payment_refund_failed', fn () => $this->service->refundPaymentsForClaim($return->fresh(), 'rejected'));
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
        $this->assertSame('REFUND_DECLINED', $payment->fresh()->pg_error_code);
    }

    /**
     * 환급 연동이 없으면 PG 환급을 거절하는지 검증합니다.
     *
     * @effects claim_payment_refund_without_a_listener_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_without_a_plugin_listener_is_rejected(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        $return = $this->requestedReturn();
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $this->service->approvePgPayment($payment, []);

        $this->assertClaimException('claim_payment_provider_unavailable', fn () => $this->service->refundPayment($payment->fresh()));
    }

    // ================================================================
    // 차액 정산 (D14)
    // ================================================================

    /**
     * 청구액이 줄면 차액을 부분 환급하고 결제 건을 결제 완료로 두는지 검증합니다.
     *
     * @effects claim_payment_settle_difference_refunds_a_decrease
     *
     * @return void 반환값 없음
     */
    public function test_settle_difference_refunds_the_decrease_partially_and_keeps_the_payment_paid(): void
    {
        $return = $this->requestedReturn();
        $userId = (int) $return->requested_by;
        $this->grantMileage($userId, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, $userId);

        // 청구액이 3,000 → 1,000 으로 줄었다 (운영자 금액 수정과 같은 형태로 헤더만 바꾼다).
        $return->fresh()->forceFill(['total_charge_amount' => 1000, 'claim_shipping_fee' => 1000])->save();

        $result = $this->service->settleChargeDifference($return->fresh());

        $this->assertNull($result);
        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame(2000.0, (float) $fresh->refunded_amount);
        $this->assertSame(1000.0, $fresh->netPaidAmount());
        $this->assertSame(2000.0, (float) MileageTransaction::where('claim_payment_id', $payment->id)->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->sum('amount'));
        $this->assertSame(0.0, $this->service->outstandingAmount($return->fresh()));
    }

    /**
     * 청구액이 늘면 증가분의 결제 대기 건을 만드는지 검증합니다.
     *
     * @effects claim_payment_settle_difference_charges_an_increase
     *
     * @return void 반환값 없음
     */
    public function test_settle_difference_creates_a_pending_payment_for_the_increase(): void
    {
        $return = $this->requestedReturn();
        $userId = (int) $return->requested_by;
        $this->grantMileage($userId, 10000);
        $payment = $this->service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, $userId);

        $return->fresh()->forceFill(['total_charge_amount' => 5000, 'claim_shipping_fee' => 5000])->save();

        $pending = $this->service->settleChargeDifference($return->fresh());

        $this->assertNotNull($pending);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $pending->payment_status);
        $this->assertSame(2000.0, $pending->getTotalAmount());
        $this->assertSame(2000.0, $this->service->outstandingAmount($return->fresh()));
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
    }

    /**
     * 아직 내지 않은 결제 대기 건을 새 청구액으로 고치는지 검증합니다.
     *
     * @scenario method=card, timing=on_request, outcome=approved
     *
     * @effects claim_payment_partial_payment_stays_in_payment_pending
     *
     * @return void 반환값 없음
     */
    public function test_settle_difference_updates_an_unpaid_pending_payment_to_the_new_charge(): void
    {
        $return = $this->requestedReturn();
        $pending = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $return->fresh()->forceFill(['total_charge_amount' => 4500, 'claim_shipping_fee' => 4500])->save();

        $updated = $this->service->settleChargeDifference($return->fresh());

        $this->assertSame($pending->id, $updated->id);
        $this->assertSame(4500.0, (float) $updated->amount);
        $this->assertSame(1, ClaimPayment::count());
    }

    /**
     * 청구액이 0 원이 되면 결제 대기 건을 취소하는지 검증합니다.
     *
     * @effects claim_payment_settle_difference_cancels_the_pending_row_at_zero
     *
     * @return void 반환값 없음
     */
    public function test_settle_difference_cancels_the_pending_payment_when_the_charge_drops_to_zero(): void
    {
        $return = $this->requestedReturn();
        $pending = $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        $return->fresh()->forceFill(['total_charge_amount' => 0, 'claim_shipping_fee' => 0, 'charge_method' => null])->save();

        $this->assertNull($this->service->settleChargeDifference($return->fresh()));
        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $pending->fresh()->payment_status);
    }

    // ================================================================
    // 주문 합계
    // ================================================================

    /**
     * 주문의 클레임 추가결제 합계가 부분 환급을 뺀 실수납액의 합인지 검증합니다.
     *
     * @effects claim_payment_order_paid_total_excludes_refunded_amounts
     *
     * @return void 반환값 없음
     */
    public function test_order_paid_total_sums_net_paid_amounts_across_claims(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $userId = (int) $order->user_id;
        $this->grantMileage($userId, 20000);

        $first = $this->returnService->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $userId,
        ]);
        $payment = $this->service->initiateForRequest($first, PaymentMethodEnum::POINT->value);
        $this->service->pay($payment, PaymentMethodEnum::POINT->value, $userId);

        $this->assertSame(3000.0, $this->service->paidTotalForOrder($order)['amount']);

        $this->service->refundPayment($payment->fresh(), 1000);

        $this->assertSame(2000.0, $this->service->paidTotalForOrder($order)['amount']);
    }

    /**
     * 보류에서 누른 승인은 결제행을 만들되 신청서를 보류에 그대로 둔다. (S3-D7)
     *
     * 승인의 출발점은 접수만이 아니다 — 보류도 승인으로 갈 수 있다(전이표). 그런데 보류에서
     * 결제대기로는 갈 수 없어서, 결제대기를 거치려 들면 전이가 거절을 던진다. 그때 결제행은
     * 이미 커밋된 뒤라 **신청서는 보류에 얼어붙고 낼 수 없는 결제행만 남는다** — 다시 승인을
     * 눌러도 같은 자리에서 같은 거절이라 운영자에게는 반려·철회밖에 남지 않는다
     * (실측 EX20260915-0853360266).
     *
     * @scenario method=card, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_on_hold_approval_keeps_the_claim_in_place
     *
     * @return void 반환값 없음
     */
    public function test_approval_from_on_hold_creates_the_payment_without_moving_the_claim(): void
    {
        $exchange = $this->requestedExchange(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $this->exchangeService->transition($exchange, ExchangeStatusEnum::ON_HOLD);

        $payment = $this->service->requestPaymentOnApproval($exchange->fresh(), $this->createAdminUser()->id);

        $this->assertNotNull($payment, '청구액이 남은 건이므로 결제행은 만들어야 한다.');
        $this->assertSame(6000.0, (float) $payment->getTotalAmount());
        $this->assertSame(
            ExchangeStatusEnum::ON_HOLD,
            $exchange->fresh()->exchange_status,
            '보류에서 결제대기로 옮기면 전이가 거절돼 신청서가 얼어붙는다.',
        );
    }

    /**
     * 그 결제가 확정되면 보류에서 곧장 승인으로 잇는다. (S3-D7)
     *
     * 결제대기를 건너뛰었으니 확정도 그 자리를 지나지 않는다 — `confirm()` 의 `paid` 전이는
     * 결제대기 건에서만 일어나고, 승인 시점 결제의 자동 승인이 그 뒤를 잇는다. 접수에서 온
     * 건과 종착이 같아야 한다.
     *
     * @scenario method=card, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_on_hold_approval_keeps_the_claim_in_place
     *
     * @return void 반환값 없음
     */
    public function test_paying_that_claim_advances_it_from_on_hold_to_approved(): void
    {
        $exchange = $this->requestedExchange(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $this->exchangeService->transition($exchange, ExchangeStatusEnum::ON_HOLD);

        $payment = $this->service->requestPaymentOnApproval($exchange->fresh(), $this->createAdminUser()->id);

        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 6000]);

        $this->service->approvePgPayment(
            $payment->fresh(),
            ['paymentKey' => 'pk_s3d7', 'orderId' => $payment->payment_number, 'amount' => 6000],
        );

        $this->assertSame(
            ExchangeStatusEnum::APPROVED,
            $exchange->fresh()->exchange_status,
            '결제가 확정됐는데 보류에 남으면 운영자가 손으로 풀어야 한다.',
        );
    }

    /**
     * 보류에서 승인해 낸 추가결제도 「결제 완료」 알림(고객·운영자)을 한 번 낸다. 정상 경로도 한 번이다.
     *
     * 결제완료 알림은 신청서가 `paid` 로 옮겨질 때 나가는데, 보류에서 승인한 건은 `paid` 를 지나지 않고 곧장
     * 승인으로 간다 — 그래서 운영자는 고객이 추가금을 낸 사실을 알림으로 받지 못했다(2026-10-07 실측:
     * EX20261006-1431076762 보류→[승인]→마일리지 결제, exchange_payment_completed_admin 0건). 결제 확정 사실
     * 훅에서 `paid` 를 거치지 않은 건에만 보내 정상 경로와 겹치지 않게 한다.
     *
     * @scenario method=card, timing=on_approval, outcome=approved
     *
     * @effects claim_payment_completed_notice_fires_once_on_every_path
     *
     * @return void 반환값 없음
     */
    public function test_payment_completed_notice_fires_once_on_the_on_hold_and_normal_paths(): void
    {
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.notify_payment_completed', function () use (&$fired) {
            $fired++;
        });

        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true, ['paid_amount' => 6000]);

        // 보류 → 승인 경로
        $held = $this->requestedExchange(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $this->exchangeService->transition($held, ExchangeStatusEnum::ON_HOLD);
        $payment = $this->service->requestPaymentOnApproval($held->fresh(), $this->createAdminUser()->id);
        $this->service->approvePgPayment($payment->fresh(), ['paymentKey' => 'pk_d8a', 'orderId' => $payment->payment_number, 'amount' => 6000]);

        $this->assertSame(ExchangeStatusEnum::APPROVED, $held->fresh()->exchange_status);
        $this->assertSame(1, $fired, '보류에서 승인해 낸 추가결제도 결제 완료를 한 번 알린다.');

        // 접수 → 승인(결제대기) 정상 경로 — 결제완료 전이가 이미 알리므로 겹치면 안 된다
        $fired = 0;
        $normal = $this->requestedExchange(ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $payment = $this->service->requestPaymentOnApproval($normal->fresh(), $this->createAdminUser()->id);
        $this->service->approvePgPayment($payment->fresh(), ['paymentKey' => 'pk_d8b', 'orderId' => $payment->payment_number, 'amount' => 6000]);

        $this->assertSame(1, $fired, '정상 경로에서 결제 완료 알림이 두 번 나가면 안 된다.');
    }
}
