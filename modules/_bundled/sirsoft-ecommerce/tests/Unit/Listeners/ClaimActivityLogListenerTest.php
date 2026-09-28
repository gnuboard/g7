<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\ActivityLog;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 활동 로그 리스너 테스트 (S7 / §4-8)
 *
 * 검증 축:
 * - 접수·전이·금액 수정이 각각 한 줄씩 남고 action / description_key 가 다국어 정의와 맞는다
 * - 상태 값은 properties 에 원값으로 남는다(문구에 라벨을 박제하지 않는다)
 * - 전이 기록 기준은 캡처된 목표 상태다 — 큐 지연 중 재전이된 모델의 "지금" 상태가 아니다
 * - 금액 수정은 코어 `ChangeDetector` 와 같은 changes 구조(field/label_key/old/new/type)를 만든다
 * - 서비스 전이 한 번이 로그 두 줄을 남기지 않는다(구체 전이 훅 이중 구독 회귀 차단)
 * - 상품 단위 전이(D25)는 헤더가 따라 움직이지 않아도 그 자체로 한 줄을 남긴다
 * - 결제 대기 건 취소는 신청서를 해석하지 못하는 배치 경로에서도 기록된다
 */
class ClaimActivityLogListenerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected ClaimActivityLogListener $listener;

    /**
     * 통화 설정·사유 카탈로그를 시드하고 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->listener = app(ClaimActivityLogListener::class);
    }

    /**
     * 테스트용 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 신청 상태의 반품 신청서를 만듭니다. (고객 접수 · 환불액 차감)
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 단일 상품 주문을 만든다)
     * @return OrderReturn 신청 상태 반품 신청서
     */
    private function requestedReturn(?Order $order = null): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 반품 접수는 신청번호를 실은 활동 로그 한 줄을 남긴다.
     *
     * @effects request_writes_one_activity_row_with_the_claim_number
     *
     * @return void 반환값 없음
     */
    public function test_requesting_a_return_writes_one_log_row_with_the_claim_number(): void
    {
        ActivityLog::query()->delete();

        $return = $this->requestedReturn();

        $logs = ActivityLog::where('action', 'claim_return.request')->get();

        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('sirsoft-ecommerce::activity_log.description.claim_return_request', $log->description_key);
        $this->assertSame($return->return_number, $log->description_params['claim_number']);
        $this->assertSame('return', $log->properties['claim_type']);
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $log->properties['status']);
        $this->assertSame($return->id, $log->loggable_id);
    }

    /**
     * 서비스 전이 한 번은 상태 변경 로그 정확히 한 줄이다.
     *
     * @effects service_transition_writes_exactly_one_status_change_row
     *
     * @return void 반환값 없음
     */
    public function test_a_service_transition_writes_exactly_one_status_change_row(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);

        $logs = ActivityLog::where('action', 'claim_return.status_change')->get();

        $this->assertCount(
            1,
            $logs,
            '전이는 status_changed 한 축으로만 기록합니다 — 구체 훅까지 구독하면 한 전이가 두 줄이 됩니다.'
        );
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $logs->first()->properties['previous_status']);
        $this->assertSame(ReturnStatusEnum::APPROVED->value, $logs->first()->properties['new_status']);

        // 전/후는 `changes` 에도 실린다 — 활동 로그 화면은 이 자리만 "변경 전 → 변경 후" 표로 그린다.
        $changes = $logs->first()->changes;
        $this->assertCount(1, $changes, '헤더 전이는 status 한 항목이어야 합니다.');
        $this->assertSame('status', $changes[0]['field']);
        $this->assertSame('enum', $changes[0]['type']);
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $changes[0]['old']);
        $this->assertSame(ReturnStatusEnum::APPROVED->value, $changes[0]['new']);
        $this->assertSame(ReturnStatusEnum::REQUESTED->labelKey(), $changes[0]['old_label_key']);
        $this->assertSame(ReturnStatusEnum::APPROVED->labelKey(), $changes[0]['new_label_key']);
        $this->assertSame('sirsoft-ecommerce::activity_log.fields.status', $changes[0]['label_key']);
    }

    /**
     * 같은 상태로의 재호출은 전/후 표를 만들지 않는다 — 바뀐 것이 없으면 표도 없다.
     *
     * @effects status_change_carries_before_and_after_in_changes
     *
     * @return void 반환값 없음
     */
    public function test_status_change_with_the_same_value_records_no_changes(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        $this->listener->handleStatusChanged($return, ReturnStatusEnum::REQUESTED->value, ReturnStatusEnum::REQUESTED->value);

        $this->assertNull(ActivityLog::where('action', 'claim_return.status_change')->firstOrFail()->changes);
    }

    /**
     * 상태 변경 로그는 발화 시점에 잡은 목표 상태를 적는다 — 기록 시점의 모델 상태가 아니다.
     *
     * @effects status_change_records_the_captured_target
     *
     * @return void 반환값 없음
     */
    public function test_status_change_records_the_captured_target_not_the_current_model_state(): void
    {
        $return = $this->requestedReturn();
        // 큐가 밀린 사이 신청서가 또 전이된 상황을 만든다.
        $return->forceFill(['return_status' => ReturnStatusEnum::COLLECTED->value])->save();
        ActivityLog::query()->delete();

        $this->listener->handleStatusChanged(
            $return->fresh(),
            ReturnStatusEnum::REQUESTED->value,
            ReturnStatusEnum::APPROVED->value,
        );

        $log = ActivityLog::where('action', 'claim_return.status_change')->firstOrFail();

        $this->assertSame(ReturnStatusEnum::APPROVED->value, $log->properties['new_status']);
    }

    /**
     * 금액 수정은 코어 changes 형태(항목·이전·이후)로 기록된다.
     *
     * @effects amount_edits_are_recorded_in_the_core_changes_shape
     *
     * @return void 반환값 없음
     */
    public function test_amount_edits_are_recorded_in_the_core_changes_shape(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        $this->listener->handleAmountsUpdated(
            $return,
            ['claim_shipping_fee' => 3000.0, 'total_charge_amount' => 3000.0, 'charge_method' => 'deduct_from_refund', 'admin_memo' => null],
            ['claim_shipping_fee' => 0.0, 'total_charge_amount' => 0.0, 'charge_method' => 'deduct_from_refund', 'admin_memo' => '판매자 귀책'],
            77,
        );

        $log = ActivityLog::where('action', 'claim_return.update_amounts')->firstOrFail();
        $changed = collect($log->changes)->keyBy('field');

        $this->assertSame(77, $log->properties['processed_by']);
        $this->assertCount(3, $log->changes, '값이 실제로 바뀐 항목만 남아야 합니다.');
        // JSON 왕복에서 소수점 없는 실수는 정수로 돌아온다 — 값의 동일성만 본다.
        $this->assertEquals(3000, $changed['claim_shipping_fee']['old']);
        $this->assertEquals(0, $changed['claim_shipping_fee']['new']);
        $this->assertSame(
            'sirsoft-ecommerce::activity_log.fields.claim_shipping_fee',
            $changed['claim_shipping_fee']['label_key']
        );
        $this->assertSame('currency', $changed['claim_shipping_fee']['type']);
        $this->assertArrayNotHasKey('charge_method', $changed->all(), '바뀌지 않은 항목은 이력에 남기지 않습니다.');
    }

    /**
     * 실제로 바뀐 금액이 없으면 changes 를 비운다.
     *
     * @effects amount_edits_with_no_effective_change_record_no_changes
     *
     * @return void 반환값 없음
     */
    public function test_amount_edits_with_no_effective_change_record_no_changes(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        $this->listener->handleAmountsUpdated($return, ['total_charge_amount' => 3000.0], ['total_charge_amount' => 3000.0]);

        $log = ActivityLog::where('action', 'claim_return.update_amounts')->firstOrFail();

        $this->assertNull($log->changes);
    }

    /**
     * 고객이 신청 내용(상세 사유·환불 계좌)을 고치면 한 줄이 남는다 — 운영자 대리 수정이 아니라는 표시와 함께.
     * 환불 계좌는 「바뀌었다」 는 사실만 남고 계좌번호·예금주는 로그 어디에도 실리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects customer_request_update_is_logged_with_masked_refund_bank
     */
    public function test_customer_request_update_is_logged_without_the_account_number(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        app(OrderReturnService::class)->updateRequest($return, [
            'reason_detail' => '사이즈가 작음',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '98765432101234', 'holder' => '홍길동'],
        ]);

        $log = ActivityLog::where('action', 'claim_return.request_update')->firstOrFail();
        $this->assertSame('sirsoft-ecommerce::activity_log.description.claim_return_request_update', $log->description_key);
        $this->assertFalse($log->properties['operator']);

        $changed = collect($log->changes)->keyBy('field');
        $this->assertNull($changed['reason_detail']['old']);
        $this->assertSame('사이즈가 작음', $changed['reason_detail']['new']);
        $this->assertNull($changed['refund_bank']['old']);
        $this->assertNull($changed['refund_bank']['new']);
        $this->assertSame('sirsoft-ecommerce::activity_log.values.masked_change', $changed['refund_bank']['new_label_key']);
        $this->assertArrayNotHasKey('reason', $changed->all(), '바뀌지 않은 사유는 이력에 남기지 않습니다.');

        $serialized = json_encode($log->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('98765432101234', $serialized, '환불 계좌번호가 활동 로그에 남았습니다.');
        $this->assertStringNotContainsString('홍길동', $serialized, '예금주가 활동 로그에 남았습니다.');
    }

    // ================================================================
    // 상품 단위 전이 (D25)
    // ================================================================

    /**
     * 상품 두 건짜리 반품 신청서와 각 상품 행 ID 를 만듭니다.
     *
     * @return array{0: OrderReturn, 1: int, 2: int} [신청서, 상품A 행 ID, 상품B 행 ID]
     */
    private function twoItemReturn(): array
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ]));
        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::DELIVERED);

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => $order->options->map(fn ($option) => [
                'order_option_id' => $option->id,
                'quantity' => (int) $option->quantity,
            ])->all(),
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);

        $idFor = fn (int $productOptionId): int => (int) $return->returnOptions
            ->firstWhere(fn ($item) => (int) $order->options->firstWhere('id', $item->order_option_id)?->product_option_id === $productOptionId)
            ->id;

        return [$return, $idFor((int) $optionA->id), $idFor((int) $optionB->id)];
    }

    /**
     * 헤더가 움직이지 않아도 상품 단위 전이는 기록된다.
     *
     * @effects item_level_transition_is_recorded_even_when_the_header_does_not_move
     *
     * @return void 반환값 없음
     */
    public function test_an_item_level_transition_is_recorded_even_when_the_header_does_not_move(): void
    {
        [$return, $itemA] = $this->twoItemReturn();
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
        ActivityLog::query()->delete();

        // 상품 A 만 반려한다 — 살아 있는 상품(B)이 신청 상태에 그대로 있어 헤더는 움직이지 않는다.
        $result = app(OrderReturnService::class)->transitionOption(
            $return->fresh(['returnOptions']),
            [$itemA],
            ReturnStatusEnum::REJECTED,
            ['processed_by' => $return->requested_by],
        );

        $this->assertSame([$itemA], $result['succeeded'], '전이가 건너뛰어지면 이 회귀의 전제가 성립하지 않습니다.');

        $this->assertCount(
            0,
            ActivityLog::where('action', 'claim_return.status_change')->get(),
            '살아 있는 상품 상태가 갈리면 헤더는 움직이지 않습니다 — 헤더 줄이 생기면 이 회귀의 전제가 깨진 것입니다.'
        );

        $logs = ActivityLog::where('action', 'claim_return.option_status_change')->get();

        $this->assertCount(
            1,
            $logs,
            '헤더가 움직이지 않는 조합에서 상품 단위 전이가 이력에 남지 않으면 운영자 행위의 흔적이 사라집니다.'
        );

        $log = $logs->first();
        $this->assertSame(
            'sirsoft-ecommerce::activity_log.description.claim_return_option_status_change',
            $log->description_key
        );
        $this->assertSame($return->return_number, $log->description_params['claim_number']);
        $this->assertSame('1', $log->description_params['count']);
        $this->assertSame(ReturnStatusEnum::REJECTED->value, $log->properties['new_status']);
        $this->assertSame((int) $return->requested_by, $log->properties['processed_by']);
        $this->assertSame($itemA, $log->properties['options'][0]['option_id']);
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $log->properties['options'][0]['previous']);
        $this->assertSame($return->id, $log->loggable_id);

        // 상품별 전/후 — 코어 일괄 형식(신청 상품 ID 키)으로 실려야 화면이 상품마다 표를 그린다.
        $changes = $log->changes;
        $this->assertArrayHasKey($itemA, $changes, '상품 단위 전이의 changes 는 신청 상품 ID 를 키로 갖습니다.');
        $this->assertSame('option_status', $changes[$itemA][0]['field']);
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $changes[$itemA][0]['old']);
        $this->assertSame(ReturnStatusEnum::REJECTED->value, $changes[$itemA][0]['new']);
        $this->assertSame(ReturnStatusEnum::REJECTED->labelKey(), $changes[$itemA][0]['new_label_key']);
    }

    /**
     * 상품 전이가 헤더까지 옮기면 두 사실을 모두 기록한다.
     *
     * @effects item_level_transition_that_moves_the_header_records_both_facts
     *
     * @return void 반환값 없음
     */
    public function test_an_item_level_transition_that_moves_the_header_records_both_facts(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();
        ActivityLog::query()->delete();

        app(OrderReturnService::class)->transitionOption(
            $return->fresh(['returnOptions']),
            [$itemA, $itemB],
            ReturnStatusEnum::APPROVED,
        );

        // 상품을 옮겼고, 그래서 신청서도 옮겨졌다 — 이중 기록이 아니라 층이 다른 두 사실이다.
        $this->assertCount(1, ActivityLog::where('action', 'claim_return.option_status_change')->get());
        $this->assertCount(1, ActivityLog::where('action', 'claim_return.status_change')->get());
        $this->assertCount(
            2,
            ActivityLog::where('action', 'claim_return.option_status_change')->firstOrFail()->properties['options'],
            '한 번의 지시로 옮긴 상품은 한 줄에 모아 적습니다.'
        );
    }

    /**
     * 아무것도 옮기지 않은 상품 단위 호출은 기록하지 않는다.
     *
     * @effects item_level_call_that_moves_nothing_records_nothing
     *
     * @return void 반환값 없음
     */
    public function test_an_item_level_call_that_moves_nothing_records_nothing(): void
    {
        [$return, $itemA] = $this->twoItemReturn();
        app(OrderReturnService::class)->transitionOption($return->fresh(['returnOptions']), [$itemA], ReturnStatusEnum::REJECTED);
        ActivityLog::query()->delete();

        // 이미 반려된 상품에 반려를 다시 지시하면 서비스가 건너뛴다 — 바뀐 것이 없으면 이력도 없다.
        app(OrderReturnService::class)->transitionOption($return->fresh(['returnOptions']), [$itemA], ReturnStatusEnum::REJECTED);

        $this->assertCount(0, ActivityLog::where('action', 'claim_return.option_status_change')->get());
    }

    // ================================================================
    // 클레임 추가결제 대기 취소
    // ================================================================

    /**
     * 추가 청구가 붙은(결제대기) 반품 신청서를 접수합니다.
     *
     * @return OrderReturn 접수된 반품 신청서
     */
    private function payOnRequestReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 결제 대기 건 취소는 사유와 함께 기록된다.
     *
     * @effects pending_claim_payment_cancellation_is_recorded_with_its_reason
     *
     * @return void 반환값 없음
     */
    public function test_cancelling_a_pending_claim_payment_is_recorded_with_its_reason(): void
    {
        $return = $this->payOnRequestReturn();

        $service = app(ClaimPaymentService::class);
        $payment = $service->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        ActivityLog::query()->delete();

        $cancelled = $service->cancelPendingPayments($return->fresh());

        $this->assertSame(1, $cancelled);

        $log = ActivityLog::where('action', 'claim_payment.cancel')->firstOrFail();

        $this->assertSame(
            'sirsoft-ecommerce::activity_log.description.claim_payment_cancel',
            $log->description_key
        );
        $this->assertSame($payment->payment_number, $log->description_params['payment_number']);
        $this->assertSame(ClaimPaymentService::CANCEL_REASON_CHARGE_SETTLED, $log->properties['reason']);
        $this->assertSame('return', $log->properties['claim_type']);
        $this->assertSame($return->return_number, $log->properties['claim_number']);
        $this->assertSame($payment->id, $log->loggable_id);
    }

    /**
     * 배치가 결제 대기 건을 취소하면 신청서를 해석하지 못해도 기록된다.
     *
     * @effects pending_payment_cancelled_by_the_reaper_is_recorded_without_a_claim
     *
     * @return void 반환값 없음
     */
    public function test_a_pending_payment_cancelled_by_the_reaper_is_recorded_without_a_claim(): void
    {
        $return = $this->payOnRequestReturn();
        $payment = app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        ActivityLog::query()->delete();

        // 신청서를 해석하지 못하는 고아 건도 이력에는 남아야 한다.
        $this->listener->handlePaymentCancelled(
            $payment->fresh(),
            null,
            ClaimPaymentService::CANCEL_REASON_ORPHAN_REAPED,
        );

        $log = ActivityLog::where('action', 'claim_payment.cancel')->firstOrFail();

        $this->assertSame(ClaimPaymentService::CANCEL_REASON_ORPHAN_REAPED, $log->properties['reason']);
        $this->assertNull($log->properties['claim_number']);
        $this->assertSame('return', $log->properties['claim_type'], '신청서를 못 찾아도 결제 건 자신이 유형을 안다.');
    }

    /**
     * 추가결제 환급 로그는 이번에 되돌린 금액을 문구에 싣는다 — 번호만 있으면 운영자가 얼마를 돌려줬는지
     * 활동 로그만으로 알 수 없다(부분 환급이면 결제 줄 금액과도 다르다).
     *
     * @effects claim_payment_refund_log_names_the_refunded_amount
     *
     * @return void 반환값 없음
     */
    public function test_claim_payment_refund_log_names_the_refunded_amount(): void
    {
        $return = $this->payOnRequestReturn();
        $payment = app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        ActivityLog::query()->delete();

        $this->listener->handlePaymentRefunded($return->fresh(), $payment->fresh(), 5000.0);

        $log = ActivityLog::where('action', 'claim_payment.refund')->firstOrFail();
        $expected = ecommerce_format_price(5000.0, $payment->currency_code);

        $this->assertSame('sirsoft-ecommerce::activity_log.description.claim_payment_refund_amount', $log->description_key);
        $this->assertSame($expected, $log->description_params['amount'] ?? null);
        $this->assertStringContainsString($expected, __($log->description_key, $log->description_params, 'ko'));
        $this->assertStringContainsString($payment->payment_number, __($log->description_key, $log->description_params, 'ko'));
    }

    /**
     * 금액을 싣기 전에 남은 환급 로그(번호만 있는 행)는 옛 키로 그대로 읽힌다 — 옛 키 문구에 새 자리표시자를
     * 넣으면 과거 로그가 「(CP…, :amount)」 처럼 자리표시자를 그대로 드러낸다(3차 실측 회귀 2026-09-26).
     *
     * @effects claim_payment_refund_legacy_log_renders_without_placeholders
     *
     * @return void 반환값 없음
     */
    public function test_legacy_claim_payment_refund_log_renders_without_placeholders(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $text = __('sirsoft-ecommerce::activity_log.description.claim_payment_refund', ['payment_number' => 'CP-LEGACY'], $locale);

            $this->assertStringContainsString('CP-LEGACY', $text);
            $this->assertStringNotContainsString(':', str_replace('CP-LEGACY', '', $text), "{$locale} 옛 환급 로그에 채워지지 않은 자리표시자가 남는다");
        }
    }
}
