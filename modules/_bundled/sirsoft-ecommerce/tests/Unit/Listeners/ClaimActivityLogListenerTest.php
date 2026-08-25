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

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->listener = app(ClaimActivityLogListener::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

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
    }

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

    public function test_amount_edits_with_no_effective_change_record_no_changes(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        $this->listener->handleAmountsUpdated($return, ['total_charge_amount' => 3000.0], ['total_charge_amount' => 3000.0]);

        $log = ActivityLog::where('action', 'claim_return.update_amounts')->firstOrFail();

        $this->assertNull($log->changes);
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
    }

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
}
