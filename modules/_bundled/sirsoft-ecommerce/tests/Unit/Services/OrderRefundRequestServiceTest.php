<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소·환불 신청 분기 서비스 테스트 (S5 · D2)
 *
 * 검증 축:
 * - 분기 판정 — 즉시취소 가능 상태 / 환불신청 가능 상태 / 혼재 거절 / 대상 아님
 * - 자동 전이 분기(구 즉시취소) — 환불신청서를 만들고 요청 → 승인 → 완료를 실제로 거치며, 완료 시점에 취소신청서가 생긴다
 * - 환불신청서 분기 — 출처 direct 환불신청서 + 상품 행이 REQUESTED 로 접수되고, 수량 잠금이 두 번째 신청을 막는다
 * - 자동 승인 설정 / 철회 / 승인 전 수정 / 전이 게이트
 * - 환불수단 ↔ 결제수단 정합, 운영자 금액 수정의 상품 행 반영, 빈 상품 목록 가드, 같은 전이 중복 지시
 *
 * 시나리오 SSoT: tests/scenarios/order-refund-request-service.yaml (전이 동시성은 refund-transition-concurrency.yaml)
 */
class OrderRefundRequestServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected OrderRefundRequestService $service;

    /**
     * 테스트 통화 설정·클레임 사유를 준비하고, 자동 전이 분기가 타는 PG 환불 훅을 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(OrderRefundRequestService::class);

        // 즉시취소 분기는 PG 환불 훅을 타므로 성공으로 고정한다.
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TEST_REFUND_TXN',
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * 테스트 통화 설정을 지워 다음 테스트에 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 상품준비중(환불신청 가능) 상태의 주문을 만듭니다.
     *
     * @param  int  $quantity  수량
     * @return Order 주문
     */
    protected function preparingOrder(int $quantity = 1): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, $quantity, $policy);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);
    }

    /**
     * 주문 전량을 신청하는 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int|null  $quantity  수량 (null 이면 전량)
     * @return array 페이로드
     */
    protected function payload(Order $order, ?int $quantity = null): array
    {
        return [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity ?? (int) $order->options->first()->quantity]],
            'reason_code' => 'changed_mind',
            'reason_detail' => '단순 변심',
            'requested_by' => $order->user_id,
        ];
    }

    // ================================================================
    // 분기 판정
    // ================================================================

    /**
     * 결제완료 상품은 자동 전이 분기(auto)로 판정됩니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects payment_complete_items_resolve_to_the_auto_branch
     *
     * @return void 반환값 없음
     */
    public function test_payment_complete_items_resolve_to_the_auto_branch(): void
    {
        $order = $this->paidClaimOrder();

        $this->assertSame('auto', $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * 상품준비중 상품은 환불신청 분기(request)로 판정됩니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects preparing_items_resolve_to_the_request_branch
     *
     * @return void 반환값 없음
     */
    public function test_preparing_items_resolve_to_the_request_branch(): void
    {
        $order = $this->preparingOrder();

        $this->assertSame('request', $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * 배송완료 상품은 환불 신청 대상이 아니라 `claim_refund_not_requestable` 로 거절됩니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects delivered_items_are_not_refund_requestable
     *
     * @return void 반환값 없음
     */
    public function test_delivered_items_are_not_refund_requestable(): void
    {
        $order = $this->deliveredClaimOrder();

        $this->assertClaimException('claim_refund_not_requestable', fn () => $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * 두 분기의 상품이 한 신청에 섞이면 `claim_refund_branch_mixed` 로 신청 전체를 거절합니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects mixed_branches_are_rejected
     *
     * @return void 반환값 없음
     */
    public function test_mixed_branches_are_rejected(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);
        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]));
        $order = $this->attachPolicyShippings($order, $policy);
        $order->options[0]->forceFill(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE])->save();
        $order->options[1]->forceFill(['option_status' => OrderStatusEnum::PREPARING])->save();
        $order = $order->fresh(['options']);

        $this->assertClaimException('claim_refund_branch_mixed', fn () => $this->service->resolveBranch($order, [
            ['order_option_id' => $order->options[0]->id, 'quantity' => 1],
            ['order_option_id' => $order->options[1]->id, 'quantity' => 1],
        ]));
    }

    /**
     * 주문에 없는 상품 행은 `claim_option_not_found`, 빈 목록은 `claim_items_required` 로 거절됩니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects an_unknown_option_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_an_unknown_option_is_rejected(): void
    {
        $order = $this->paidClaimOrder();

        $this->assertClaimException('claim_option_not_found', fn () => $this->service->resolveBranch($order, [['order_option_id' => 999999, 'quantity' => 1]]));
        $this->assertClaimException('claim_items_required', fn () => $this->service->resolveBranch($order, []));
    }

    /**
     * 한 상태가 두 설정 목록에 함께 들어 있으면 자동 전이 분기가 우선합니다.
     *
     * @scenario concern=branch_resolution
     *
     * @effects auto_branch_wins_when_both_lists_contain_the_same_status
     *
     * @return void 반환값 없음
     */
    public function test_auto_branch_wins_when_both_lists_contain_the_same_status(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.refund_request_statuses', [OrderStatusEnum::PAYMENT_COMPLETE->value, OrderStatusEnum::PREPARING->value]);
        $order = $this->paidClaimOrder();

        $this->assertSame('auto', $this->service->branchForStatus(OrderStatusEnum::PAYMENT_COMPLETE));
    }

    // ================================================================
    // 미리보기
    // ================================================================

    /**
     * 미리보기는 분기와 환불 계산을 돌려주고 신청서를 만들지 않습니다.
     *
     * @scenario concern=preview
     *
     * @effects preview_returns_the_branch_and_the_refund_calculation
     *
     * @return void 반환값 없음
     */
    public function test_preview_returns_the_branch_and_the_refund_calculation(): void
    {
        $order = $this->preparingOrder();

        $preview = $this->service->previewRefund($order, $this->payload($order)['items']);

        $this->assertSame('request', $preview['branch']);
        $this->assertSame(29000.0, (float) $preview['adjustment']->getTotalRefundAmount());
        $this->assertSame(0, OrderRefund::count());
    }

    /**
     * 미리보기도 신청과 같은 수량 잠금을 적용해 이미 신청된 수량을 거절합니다.
     *
     * @scenario concern=preview
     *
     * @effects preview_applies_the_quantity_lock_like_the_request
     *
     * @return void 반환값 없음
     */
    public function test_preview_applies_the_quantity_lock_like_the_request(): void
    {
        $order = $this->preparingOrder(2);
        $this->service->request($order, $this->payload($order, 2));

        $this->assertClaimException('claim_quantity_locked', fn () => $this->service->previewRefund($order->fresh(['options']), $this->payload($order, 1)['items']));
    }

    /**
     * 수정 중인 환불신청서 자신의 수량은 미리보기 잠금에서 빠집니다.
     *
     * @scenario concern=preview
     *
     * @effects preview_excludes_the_refund_request_being_edited_from_the_lock
     *
     * @return void 반환값 없음
     */
    public function test_preview_excludes_the_refund_request_being_edited_from_the_lock(): void
    {
        $order = $this->preparingOrder(2);
        $result = $this->service->request($order, $this->payload($order, 2));

        $preview = $this->service->previewRefund(
            $order->fresh(['options']),
            $this->payload($order, 2)['items'],
            excludeRefundId: $result['refund']->id,
        );

        $this->assertSame('request', $preview['branch']);
        $this->assertSame(58000.0, (float) $preview['adjustment']->getTotalRefundAmount());
    }

    // ================================================================
    // 자동 전이 분기 (구 즉시취소 — #599 후속 D1·D2·D3)
    // ================================================================

    /**
     * 자동 전이 분기도 **환불신청서를 만든다**. 취소신청서는 그 환불이 완료되는 순간에 생긴다 —
     * 예전에는 환불신청서 없이 취소 기록만 남아, 결제완료 상품을 환불한 고객에게 환불 이력이
     * 아예 보이지 않았다(#599 제보).
     *
     * @scenario concern=auto_branch
     *
     * @effects auto_branch_files_a_refund_request_and_records_the_cancellation
     *
     * @return void 반환값 없음
     */
    public function test_auto_branch_files_a_refund_request_and_records_the_cancellation(): void
    {
        $order = $this->paidClaimOrder();

        $result = $this->service->request($order, $this->payload($order));

        $refund = $result['refund'];
        $this->assertSame('auto', $result['branch']);
        $this->assertNotNull($refund);
        $this->assertSame(RefundSourceTypeEnum::DIRECT, $refund->refund_source_type);
        $this->assertSame('changed_mind', $refund->reason_code);
        $this->assertSame($order->user_id, (int) $refund->requested_by);
        // 신청자·승인 시각이 채워진다 — 즉시취소가 비워 두던 두 칸이다.
        $this->assertNotNull($refund->approved_at);

        // 취소신청서는 환불완료 시점에 생긴다 (D3).
        $cancel = OrderCancel::where('order_id', $order->id)->first();
        $this->assertInstanceOf(OrderCancel::class, $cancel);
        $this->assertSame('changed_mind', $cancel->cancel_reason_type);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
    }

    /**
     * 단계를 건너뛰지 않는다(D16) — 한 트랜잭션 안이어도 `요청 → 승인 → 완료` 를 실제로 거친다.
     * 최종 상태를 바로 INSERT 하면 활동로그가 한 줄만 남아 운영자가 승인 경위를 되짚을 수 없다.
     *
     * @scenario concern=auto_branch
     *
     * @effects auto_advance_walks_every_status_step
     *
     * @return void 반환값 없음
     */
    public function test_auto_advance_walks_every_status_step(): void
    {
        $order = $this->paidClaimOrder();
        $steps = [];
        HookManager::addAction('sirsoft-ecommerce.claim.refund.status_changed', function ($refund, $previous = null, $target = null) use (&$steps) {
            $steps[] = $previous.'->'.$target;
        });

        $this->service->request($order, $this->payload($order));

        $this->assertContains('requested->approved', $steps);
        $this->assertContains('approved->completed', $steps);
    }

    // ================================================================
    // 환불신청서 분기
    // ================================================================

    /**
     * 환불신청 분기는 출처 direct 환불신청서와 REQUESTED 상품 행을 만들고, 원 주문옵션은 판매 상태로 둡니다.
     *
     * @scenario concern=request_branch
     *
     * @effects refund_branch_files_a_direct_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_refund_branch_files_a_direct_refund_request(): void
    {
        $order = $this->preparingOrder();
        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.claim.refund.requested', function ($refund) use (&$fired) {
            $fired[] = $refund->refund_number;
        });

        $result = $this->service->request($order, $this->payload($order));

        $refund = $result['refund'];
        $this->assertSame('request', $result['branch']);
        $this->assertNull($result['cancel']);
        $this->assertSame(RefundSourceTypeEnum::DIRECT, $refund->refund_source_type);
        $this->assertNull($refund->refund_source_id);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertSame('changed_mind', $refund->reason_code);
        $this->assertSame('단순 변심', $refund->reason_detail);
        $this->assertSame($order->user_id, (int) $refund->requested_by);
        $this->assertSame(29000.0, (float) $refund->refund_amount);
        $this->assertCount(1, $refund->refundOptions);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refundOptions->first()->option_status);
        $this->assertSame(1, (int) $refund->refundOptions->first()->quantity);
        $this->assertSame([$refund->refund_number], $fired);
        // 원 주문옵션은 판매 상태 그대로다 — 코스 상태가 없다.
        $this->assertSame(OrderStatusEnum::PREPARING, $order->fresh()->options->first()->option_status);
    }

    /**
     * 접수된 수량은 잠겨 두 번째 신청이 거절되고, 거절된 신청은 신청서를 남기지 않습니다.
     *
     * @scenario concern=request_branch
     *
     * @effects refund_branch_locks_the_quantity_against_a_second_request
     *
     * @return void 반환값 없음
     */
    public function test_refund_branch_locks_the_quantity_against_a_second_request(): void
    {
        $order = $this->preparingOrder(2);
        $this->service->request($order, $this->payload($order, 2));

        $this->assertClaimException('claim_quantity_locked', fn () => $this->service->request($order->fresh(['options']), $this->payload($order, 1)));

        // 거절된 두 번째 신청은 신청서를 남기지 않아야 한다 — 가드가 행 생성 뒤에 있으면
        // 사용자는 422 를 보는데 잠금만 더 늘어난 채 신청서가 쌓인다.
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * 자동 승인 설정이 켜져 있으면 환불신청서가 접수와 함께 승인되고 승인 훅이 한 번 돕니다.
     *
     * @scenario concern=request_branch
     *
     * @effects refund_branch_can_auto_approve_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_refund_branch_can_auto_approve_by_setting(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_refund_request', true);
        $order = $this->preparingOrder();
        $approved = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.refund.approved', function () use (&$approved) {
            $approved++;
        });

        $result = $this->service->request($order, $this->payload($order));

        $this->assertSame(RefundStatusEnum::APPROVED, $result['refund']->refund_status);
        $this->assertNotNull($result['refund']->approved_at);
        $this->assertSame(1, $approved);
    }

    /**
     * 환불 계좌가 주어지면 은행·계좌번호·예금주를 신청서에 저장합니다.
     *
     * @scenario concern=request_branch
     *
     * @effects refund_branch_stores_the_refund_bank_when_given
     *
     * @return void 반환값 없음
     */
    public function test_refund_branch_stores_the_refund_bank_when_given(): void
    {
        $order = $this->preparingOrder();

        $result = $this->service->request($order, $this->payload($order) + [
            'refund_bank' => ['bank_code' => '004', 'account_number' => '123-456', 'holder' => '홍길동'],
        ]);

        $this->assertSame('004', $result['refund']->refund_bank_code);
        $this->assertSame('123-456', $result['refund']->refund_bank_account);
        $this->assertSame('홍길동', $result['refund']->refund_bank_holder);
    }

    /**
     * 사용하지 않는 사유 코드는 `claim_reason_unavailable` 로 거절됩니다.
     *
     * @scenario concern=request_branch
     *
     * @effects request_rejects_an_inactive_reason
     *
     * @return void 반환값 없음
     */
    public function test_request_rejects_an_inactive_reason(): void
    {
        $order = $this->preparingOrder();

        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->request($order, ['reason_code' => 'nope'] + $this->payload($order)));
    }

    // ================================================================
    // 철회 / 수정 / 전이
    // ================================================================

    /**
     * 철회는 신청서와 상품 행을 철회 상태로 닫고 수량 잠금을 풀어 같은 수량을 다시 신청할 수 있게 합니다.
     *
     * @scenario concern=withdraw_and_edit
     *
     * @effects withdraw_closes_the_request_and_releases_the_quantity
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_closes_the_request_and_releases_the_quantity(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.refund.withdrawn', function () use (&$fired) {
            $fired++;
        });

        $withdrawn = $this->service->withdraw($refund);

        $this->assertSame(RefundStatusEnum::WITHDRAWN, $withdrawn->refund_status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        // 상품 행도 **철회**로 남는다 — 반려(판매자가 거절)와 철회(구매자가 거둠)는 다른 사실이고,
        // 화면의 상품 뱃지가 이 값을 그대로 읽으므로 반려로 적으면 구매자가 자기 철회를
        // "판매자가 반려함" 으로 읽는다. 반품·교환 옵션은 이미 `withdrawn` 을 쓴다(S17 트랙 A F-4).
        $this->assertSame(RefundStatusEnum::WITHDRAWN, $withdrawn->refundOptions->first()->option_status);
        $this->assertSame(1, $fired);
        // 잠금이 풀려 같은 수량을 다시 신청할 수 있다.
        $again = $this->service->request($order->fresh(['options']), $this->payload($order));
        $this->assertSame(RefundStatusEnum::REQUESTED, $again['refund']->refund_status);
    }

    /**
     * 반려는 그대로 반려로 남는다 — 철회 분기를 더하면서 반려까지 옮겨 가면 안 된다.
     *
     * @scenario concern=withdraw_and_edit
     *
     * @effects reject_keeps_the_option_rows_rejected
     *
     * @return void 반환값 없음
     */
    public function test_reject_keeps_the_option_rows_rejected(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $rejected = $this->service->transition($refund, RefundStatusEnum::REJECTED, ['reject_reason' => '재고 없음']);

        $this->assertSame(RefundStatusEnum::REJECTED, $rejected->refund_status);
        $this->assertSame(RefundStatusEnum::REJECTED, $rejected->refundOptions->first()->option_status);
    }

    /**
     * 승인된 신청서는 철회할 수 없습니다 (`claim_not_withdrawable`).
     *
     * @scenario concern=withdraw_and_edit
     *
     * @effects withdraw_is_blocked_after_approval
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_is_blocked_after_approval(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];
        $this->service->transition($refund, RefundStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($refund->fresh()));
    }

    /**
     * 사유 수정은 승인 전에만 됩니다 — 귀책 구분도 사유를 따라가고, 승인 뒤에는 `claim_not_editable` 입니다.
     *
     * @scenario concern=withdraw_and_edit
     *
     * @effects update_changes_the_reason_before_approval_only
     *
     * @return void 반환값 없음
     */
    public function test_update_changes_the_reason_before_approval_only(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $updated = $this->service->updateRequest($refund, ['reason_code' => 'defective_product', 'reason_detail' => '불량']);

        $this->assertSame('defective_product', $updated->reason_code);
        $this->assertSame('불량', $updated->reason_detail);
        $this->assertSame('seller', $updated->fault_type->value);

        $this->service->transition($updated, RefundStatusEnum::APPROVED);
        $this->assertClaimException('claim_not_editable', fn () => $this->service->updateRequest($updated->fresh(), ['reason_detail' => 'x']));
    }

    /**
     * 전이는 환불 상태 게이트를 따릅니다 — 완료는 실행 흐름으로만 가고, 보류·반려 기록이 남으며, 반려 뒤 승인은 거절됩니다.
     *
     * @scenario concern=transition_gate
     *
     * @effects transition_follows_the_refund_gate_and_refuses_completion
     *
     * @return void 반환값 없음
     */
    public function test_transition_follows_the_refund_gate_and_refuses_completion(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transition($refund, RefundStatusEnum::COMPLETED));

        $held = $this->service->transition($refund, RefundStatusEnum::ON_HOLD);
        $this->assertSame(RefundStatusEnum::ON_HOLD, $held->refund_status);
        $this->assertNotNull($held->on_hold_at);

        $adminId = $this->createAdminUser()->id;
        $rejected = $this->service->transition($held, RefundStatusEnum::REJECTED, ['reject_reason' => '재고 확인 불가', 'processed_by' => $adminId]);
        $this->assertSame('재고 확인 불가', $rejected->reject_reason);
        $this->assertSame($adminId, (int) $rejected->processed_by);

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($rejected, RefundStatusEnum::APPROVED));
    }

    // ================================================================
    // 운영자 금액 수정 → 신청상품 행 반영 (S17 B7)
    // ================================================================

    /**
     * S1-D15: 결제수단이 지원하지 않는 환불수단은 저장되지 않는다.
     *
     * 무통장입금(dbank)은 PG 를 거치지 않았으므로 취소할 PG 거래 자체가 없다
     * (`pg_provider='manual'` · `transaction_id=NULL`). 그런데도 종전에는 어휘 검사만 있어
     * `refund_method='pg'` 가 **200 으로 저장**됐다.
     *
     * 그 다음이 조용하게 어깋난다 — `RefundExecutionService` 는 **결제수단**으로
     * PG 진입을 가르므로(`$order->payment->needsPgProvider()`), dbank 는 PG 훅을 건너뛰고
     * 계좌이체처럼 완료된다. 화면에만 「PG사 환불」이 남아 운영자는 PG 로 돌려줊c다고
     * 읽는다 — 실제로는 사람이 송금해야 끝나는 돈이다.
     *
     * 실측(#599 통합실측 세션1 · 주문 20260915-1352103470 · RF43):
     * 무통장 주문의 환불신청서에 `PG사 환불` 라디오 → [금액 저장] → **200** ·
     * `refund_method=pg` 가 그대로 박혔고 상세가 「환불 수단: PG사 환불」로 표시됐다.
     *
     * 계약: 결제수단이 PG 를 쓰지 않는 주문은 `pg` 를 거절한다.
     *
     * @scenario concern=refund_method_capability
     *
     * @effects refund_method_must_match_payment_capability
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_method_is_rejected_when_payment_has_no_pg(): void
    {
        $order = $this->preparingOrder();
        $order->payment->forceFill([
            'payment_method' => 'dbank',
            'pg_provider' => 'manual',
            'transaction_id' => null,
        ])->save();
        $order->refresh()->loadMissing('payment');

        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $this->assertClaimException(
            'claim_refund_method_unavailable',
            fn () => $this->service->updateAmounts($refund, ['refund_method' => 'pg'])
        );

        // 저장 자체가 일어나지 않아야 한다 — 접수 시 수단이 그대로 남는다.
        $this->assertNotSame(RefundMethodEnum::PG, $refund->fresh()->refund_method);
    }

    /**
     * 같은 주문에서 계좌이체 환불은 그대로 통과한다 — 과잉 차단 방지.
     *
     * @scenario concern=refund_method_capability
     *
     * @effects refund_method_must_match_payment_capability
     *
     * @return void 반환값 없음
     */
    public function test_bank_refund_method_is_allowed_for_bank_transfer_order(): void
    {
        $order = $this->preparingOrder();
        $order->payment->forceFill([
            'payment_method' => 'dbank',
            'pg_provider' => 'manual',
            'transaction_id' => null,
        ])->save();
        $order->refresh()->loadMissing('payment');

        $refund = $this->service->request($order, $this->payload($order))['refund'];
        $updated = $this->service->updateAmounts($refund, ['refund_method' => 'bank']);

        $this->assertSame(RefundMethodEnum::BANK, $updated->refund_method);
    }

    /**
     * 카드 주문을 `계좌이체` 로 환불하면 **카드 결제행의 취소 누적은 움직이지 않는다.** (S1-D24)
     *
     * `cancelled_amount` 는 「이 결제수단에서 실제로 취소된 금액」이고 PG 환불가능액을 되짚는
     * 근거다. 운영자가 카드 주문의 환불수단을 계좌로 바꿔 실행하면 PG 왕복은 일어나지 않는데
     * 이 누적만 깎여, 카드사 잔액은 그대로인 채 **장부의 환불가능액만 줄었다** — 이후 정상적인
     * 카드 환불이 한도에 먼저 걸린다.
     *
     * 실측(#599 세션1 · 1-P · 주문 109 RF 80): 결제 58,000 카드 주문에 bank 수단으로 14,000 을
     * 실행하니 200 으로 통과하고 `cancelled_amount` 가 14,000 — 환불가능액이 58,000 → 44,000
     * 으로 줄었다. 토스 잔액은 58,000 그대로였다.
     *
     * 방향은 운영 결정(2026-09-16): **허용하되 장부는 건드리지 않는다.** 손으로 계좌 송금하는
     * 운영은 막지 않고, 다만 그 송금은 카드 취소가 아니므로 카드 결제행의 누적이 아니다.
     * 돈이 나간 사실은 주문 축(`total_refunded_amount`)이 그대로 기록한다.
     *
     * @scenario concern=refund_method_capability
     *
     * @effects non_pg_refund_leaves_payment_cancelled_amount_untouched
     *
     * @return void 반환값 없음
     */
    public function test_bank_refund_on_card_order_leaves_payment_ledger_untouched(): void
    {
        // 배송준비중 = `request` 분기 — 접수 후 운영자가 금액·수단을 고칠 수 있는 상태다.
        // (`paidClaimOrder` 는 즉시 완료로 올라가 수단 수정이 막힌다.)
        $order = $this->preparingOrder();
        $order->payment->forceFill([
            'payment_method' => 'card',
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'TXN-CARD-1',
            'cancelled_amount' => 0,
        ])->save();
        $order->refresh()->loadMissing('payment');

        // PG 리스너를 등록해 둔다 — 없으면 P14 폴백으로 수단이 bank 가 되어 축이 흐려진다.
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TXN-REFUND-1',
            'error_code' => null,
            'error_message' => null,
        ]);

        $refund = $this->service->request($order, $this->payload($order))['refund'];

        // 운영자가 환불수단을 계좌이체로 바꾼다 — 실측 1-P 과 같은 조작이다.
        $this->service->updateAmounts($refund, ['refund_method' => 'bank']);

        // `request` 분기는 승인을 거쳐야 실행에 닿는다.
        $this->service->transition($refund->fresh(), RefundStatusEnum::APPROVED);

        $this->service->executeRefund($refund->fresh(), [], $order->user_id);

        $this->assertSame(
            0.0,
            (float) $order->payment->fresh()->cancelled_amount,
            'PG 를 부르지 않았는데 카드 결제행의 취소 누적이 깎이면 환불가능액이 실제와 어긋난다.'
        );
    }

    /**
     * 헤더 감액은 신청상품 행까지 내려간다.
     *
     * 행 금액은 접수 시점 1회만 기록되므로, 헤더만 고치면 상세가 접수 당시 금액을 계속
     * 말한다 — 실측에서 신청서 155,000 원 ↔ 상품행 합계 151,000 원으로 갈렸다.
     *
     * @scenario concern=amount_edit
     *
     * @effects amount_reduction_reaches_the_option_rows
     *
     * @return void 반환값 없음
     */
    public function test_amount_reduction_reaches_the_option_rows(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $this->assertSame(29000.0, (float) $refund->refundOptions->sum('refund_amount'));

        $updated = $this->service->updateAmounts($refund, ['refund_amount' => 25000]);

        $this->assertSame(25000.0, (float) $updated->refund_amount);
        $this->assertSame(25000.0, $this->optionRowTotal($updated->id));
    }

    /**
     * 증액도 같은 경로를 탄다 — 감액만 내려가면 되돌린 뒤 행이 낮은 값에 고착된다.
     *
     * @scenario concern=amount_edit
     *
     * @effects amount_change_tracks_the_option_rows_in_both_directions
     *
     * @return void 반환값 없음
     */
    public function test_amount_change_tracks_the_option_rows_in_both_directions(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $reduced = $this->service->updateAmounts($refund, ['refund_amount' => 10000]);
        $this->assertSame(10000.0, $this->optionRowTotal($reduced->id));

        $restored = $this->service->updateAmounts($reduced->fresh(), ['refund_amount' => 29000]);
        $this->assertSame(29000.0, (float) $restored->refund_amount);
        $this->assertSame(29000.0, $this->optionRowTotal($restored->id));
    }

    /**
     * 헤더 합계와 행 합계가 애초에 다른 신청서(반품 발 — 헤더에 배송비 환급이 섞인다)도
     * 감액 **비율**만큼 행이 줄어든다. 차액을 그대로 빼면 상품에 귀속되지 않는 몫까지
     * 행에서 빠져 0 원으로 잘린다(실데이터에서 확인).
     *
     * @scenario concern=amount_edit
     *
     * @effects reduction_scales_option_rows_when_header_exceeds_their_sum
     *
     * @return void 반환값 없음
     */
    public function test_reduction_scales_option_rows_when_header_exceeds_their_sum(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        // 헤더만 두 배로 올려 "행 합계 < 헤더" 상태를 만든다 (반품 발 신청서의 형태).
        $inflated = $this->service->updateAmounts($refund, ['refund_amount' => 29000]);
        $this->assertSame(29000.0, $this->optionRowTotal($inflated->id));

        // 헤더를 절반으로 — 행도 절반이 되어야 한다(0 으로 잘리지 않는다).
        $halved = $this->service->updateAmounts($inflated->fresh(), ['refund_amount' => 14500]);

        $this->assertSame(14500.0, (float) $halved->refund_amount);
        $this->assertSame(14500.0, $this->optionRowTotal($halved->id));
        $this->assertGreaterThan(0.0, $this->optionRowTotal($halved->id));
    }

    /**
     * 금액을 건드리지 않는 수정(메모 등)은 행을 손대지 않는다 — 무관한 저장이 행을 재배분하면
     * 접수 시점 근거가 이유 없이 흔들린다.
     *
     * @scenario concern=amount_edit
     *
     * @effects non_amount_update_leaves_the_option_rows_untouched
     *
     * @return void 반환값 없음
     */
    public function test_non_amount_update_leaves_the_option_rows_untouched(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];

        $before = $refund->refundOptions->pluck('refund_amount', 'id')->map(fn ($v) => (float) $v)->all();

        $this->service->updateAmounts($refund, ['admin_memo' => '확인함']);

        $after = OrderRefund::find($refund->id)->refundOptions
            ->pluck('refund_amount', 'id')->map(fn ($v) => (float) $v)->all();

        $this->assertSame($before, $after);
    }

    /**
     * 상품 행 없는 클레임은 만들어지지 않는다 — 분기를 못박은 창구에서도. (§9-1 구조 가드)
     *
     * `resolveBranch()` 에도 같은 가드가 있지만 분기를 못박은 호출부(운영자·시스템 어댑터)는
     * 그 함수를 지나지 않는다. 이미 전량 취소된 주문을 다시 취소하면 어댑터가 빈 목록을 넘기고,
     * 그러면 상품 행이 하나도 없는 문서가 생긴다 — 목록 뱃지는 1건을 세는데 상세 표는 빈다.
     *
     * @scenario concern=intake_guard
     *
     * @effects claim_intake_rejects_empty_items_even_when_the_branch_is_pinned
     *
     * @return void 반환값 없음
     */
    public function test_request_rejects_empty_items(): void
    {
        $order = $this->paidClaimOrder();

        // 고객 창구 — 분기를 재는 경로
        $this->assertClaimException('claim_items_required', fn () => $this->service->request($order, [
            'items' => [],
            'reason_code' => 'changed_mind',
        ]));

        // 운영자·시스템 창구 — 분기를 못박아 `resolveBranch()` 를 지나지 않는 경로
        $this->assertClaimException('claim_items_required', fn () => $this->service->request($order, [
            'items' => [],
            'reason_code' => 'changed_mind',
            'branch' => OrderRefundRequestService::BRANCH_AUTO,
        ]));
    }

    // ================================================================
    // 전이 동시성 (S1-D21)
    // ================================================================

    /**
     * 같은 전이를 두 번 지시해도 훅은 한 번만 돈다. (환불)
     *
     * 전이는 「현재 상태를 읽어 → 갈 수 있는지 보고 → 쓴다」 순서다. 판정을 **메모리에 들고
     * 있는 모델 값**으로 하면 동시에 들어온 두 요청이 둘 다 같은 출발 상태를 보고 둘 다 가드를
     * 통과한다. 쓰기는 같은 값이라 **상태·시각만 보면 1회처럼 보이는데** 전이 훅이 두 번 돈다.
     *
     * 그래서 이 테스트는 상태가 아니라 **「두 번째 호출이 거절되는가」** 를 판정한다 —
     * 상태는 고쳐지기 전에도 정상으로 보였으므로, 상태를 단언하면 red 가 서지 않는다.
     *
     * 세션3 이 교환·반품에서 같은 형태를 찾아 고쳤고(S3-D16 · `ClaimTransitionConcurrencyTest`),
     * 환불 `transition()` 도 글자 그대로 같은 형태였다. 환불 훅에는 활동로그·알림이 걸려 있어
     * 두 번 돌면 구매자에게 같은 알림이 2통 간다.
     *
     * **판정 축이 「예외가 나는가」가 아니라 「훅이 몇 번 돌았는가」인 이유**: 상태 기계는
     * 같은 상태로의 재진입을 허용한다(`canTransitionTo` 가 `$this === $target` 를 true 로 낸다).
     * 그래서 두 번째 호출은 **예외를 던지지 않고 통과**한다 — 예외를 단언하면 고친 뒤에도 red 다.
     * 실제 피해는 훅 중복이므로 그것을 직접 센다.
     *
     * 실측(2026-09-16 00:13:12 · RF 7 · 동시 2요청): 잠금만 넣었을 때 활동로그가
     * `requested → approved` 1줄 + **`approved → approved` 1줄** 로 두 줄 남았다.
     *
     * 되돌림 축: 훅 가드(`$current !== $target`)를 빼면 두 번 돌아 red.
     * 잠금(`findForUpdate`)을 빼면 동시 쓰기가 막히지 않는다 — **두 겹이 모두 필요하다.**
     *
     * @scenario claim_type=refund, concurrency=simultaneous
     *
     * @effects duplicate_refund_transition_fires_hooks_once
     *
     * @return void 반환값 없음
     */
    public function test_repeating_the_same_refund_transition_fires_hooks_once(): void
    {
        $order = $this->preparingOrder(2);
        $refund = $this->service->request($order, $this->payload($order, 1))['refund'];

        $fired = 0;
        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function () use (&$fired): void {
                $fired++;
            },
            10,
        );

        // 호출층이 **낡은 인스턴스를 그대로 다시 넘기는** 상황이 더블클릭의 실제 형태다.
        $this->service->transition($refund, RefundStatusEnum::APPROVED);
        $this->service->transition($refund, RefundStatusEnum::APPROVED);

        $this->assertSame(
            1,
            $fired,
            '같은 전이를 두 번 지시해도 훅은 한 번만 돈다 — 두 번 돌면 알림·활동로그가 2개가 된다'
        );
    }

    /**
     * 잠금을 넣었다고 **정상 순차 전이가 막히면 안 된다.**
     *
     * 이 대조군이 없으면 「두 번째를 거절한다」를 과하게 구현해 정상 흐름까지 막아도 green 이다.
     * 접수 → 승인 → 보류 → 승인이 그대로 진행되어야 한다.
     *
     * @scenario claim_type=refund, concurrency=sequential
     *
     * @effects sequential_refund_transition_still_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_sequential_refund_transitions_still_advance(): void
    {
        $order = $this->preparingOrder(2);
        $refund = $this->service->request($order, $this->payload($order, 1))['refund'];

        $approved = $this->service->transition($refund, RefundStatusEnum::APPROVED);
        $this->assertSame(RefundStatusEnum::APPROVED, $approved->refund_status);

        $held = $this->service->transition($approved, RefundStatusEnum::ON_HOLD);
        $this->assertSame(RefundStatusEnum::ON_HOLD, $held->refund_status);

        $again = $this->service->transition($held, RefundStatusEnum::APPROVED);
        $this->assertSame(RefundStatusEnum::APPROVED, $again->refund_status);
    }

    /**
     * 전이 뒤 훅이 읽는 **직전 상태**가 잠금 시점 값이어야 한다.
     *
     * 잠금 아래 재조회를 넣으면서 호출층 인스턴스를 갱신하지 않으면, 뒤따르는 훅이 낡은 값을
     * `previous` 로 읽어 **활동로그의 전/후 표가 실제와 어긋난다** — 상태는 맞는데 기록만 틀린다.
     *
     * @scenario claim_type=refund, concurrency=sequential
     *
     * @effects sequential_refund_transition_still_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_transition_hook_sees_the_locked_previous_status(): void
    {
        $order = $this->preparingOrder(2);
        $refund = $this->service->request($order, $this->payload($order, 1))['refund'];

        $seen = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($_refund, $previous, $target) use (&$seen): void {
                $seen[] = [$previous, $target];
            },
            10,
        );

        $this->service->transition($refund, RefundStatusEnum::APPROVED);

        $this->assertNotEmpty($seen, '전이 훅이 발화해야 한다');
        $this->assertSame(
            [RefundStatusEnum::REQUESTED->value, RefundStatusEnum::APPROVED->value],
            $seen[0],
            '훅의 직전 상태는 잠금 시점 값이어야 한다 — 낡은 값을 읽으면 활동로그 전/후가 어긋난다'
        );
    }

    /**
     * 「돌려줄 현금이 남았는가」 진리표 — 헤더 누적과 결제행 수납액 두 장부를 본다(결함 4 · 00 ⑭).
     *
     * 결제행 축은 결제가 실제로 끝난 상태(`paid`·`partial_cancelled`)일 때만 본다 — 결제행은 생성될 때부터
     * `paid_amount_base` 에 결제예정금액이 들어 있어, 상태를 보지 않으면 결제금액 기록을 끈 수동 결제완료
     * 주문(Q13)을 「현금 있음」으로 오판한다. 결제 전 주문도 결제행에 입금이 기록돼 있으면 현금이 있다(S3-D7).
     *
     * @return void 반환값 없음
     *
     * @effects has_cash_to_return_reads_header_and_payment_ledgers
     */
    public function test_has_cash_to_return_reads_header_and_payment_ledgers(): void
    {
        $order = $this->paidClaimOrder();
        $payment = $order->payment;

        // ① 결제 전 주문(결제행 입금 대기) → 돌려줄 돈이 없다
        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $payment->forceFill(['payment_status' => PaymentStatusEnum::READY])->save();
        $this->assertFalse($this->service->hasCashToReturn($order->fresh('payment')));

        // ①-b 결제 전 주문인데 결제행에 입금이 기록됨([입금확인] 「기록만」) → 있다 (S3-D7)
        $payment->forceFill(['payment_status' => PaymentStatusEnum::PAID])->save();
        $this->assertTrue($this->service->hasCashToReturn($order->fresh('payment')));

        // ② 헤더 0 + 결제행 0 → 없다 (마일리지 전액 결제)
        $order->forceFill(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE, 'total_paid_amount' => 0, 'total_refunded_amount' => 0])->save();
        $payment->forceFill(['paid_amount_base' => 0, 'cancelled_amount' => 0, 'payment_status' => PaymentStatusEnum::PAID])->save();
        $this->assertFalse($this->service->hasCashToReturn($order->fresh('payment')));

        // ③ 헤더 0 + 결제완료 결제행 수납 55,000 → 있다 (수동 결제완료로 헤더가 비어도 결제된 돈)
        $payment->forceFill(['paid_amount_base' => 55000])->save();
        $this->assertTrue($this->service->hasCashToReturn($order->fresh('payment')));

        // ④ 헤더 0 + 결제 전 결제행(결제예정금액만 든) → 없다 (00 ⑭)
        $payment->forceFill(['payment_status' => PaymentStatusEnum::READY])->save();
        $this->assertFalse($this->service->hasCashToReturn($order->fresh('payment')));

        // ⑤ 헤더 결제액 = 환불 누적(소수 오차 포함) → 없다
        $payment->forceFill(['paid_amount_base' => 0, 'payment_status' => PaymentStatusEnum::PAID])->save();
        $order->forceFill(['total_paid_amount' => 0.3, 'total_refunded_amount' => 0.1 + 0.2])->save();
        $this->assertFalse($this->service->hasCashToReturn($order->fresh('payment')));

        // ⑥ 헤더 잔여 > 0 → 있다
        $order->forceFill(['total_paid_amount' => 29000, 'total_refunded_amount' => 10000])->save();
        $this->assertTrue($this->service->hasCashToReturn($order->fresh('payment')));
    }

    /**
     * 신청상품 행 금액의 합계를 반환합니다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @return float 행 금액 합계
     */
    private function optionRowTotal(int $refundId): float
    {
        return round((float) OrderRefund::find($refundId)->refundOptions()->sum('refund_amount'), 2);
    }
}
