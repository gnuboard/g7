<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
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
 * - 즉시취소 분기 — 기존 취소 파이프라인을 그대로 실행해 취소신청서·환불이 생긴다
 * - 환불신청서 분기 — 출처 direct 환불신청서 + 상품 행이 REQUESTED 로 접수되고, 수량 잠금이 두 번째 신청을 막는다
 * - 자동 승인 설정 / 철회 / 승인 전 수정 / 전이 게이트
 */
class OrderRefundRequestServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected OrderRefundRequestService $service;

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
     * @effects payment_complete_items_resolve_to_the_auto_branch
     */
    public function test_payment_complete_items_resolve_to_the_auto_branch(): void
    {
        $order = $this->paidClaimOrder();

        $this->assertSame('auto', $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * @effects preparing_items_resolve_to_the_request_branch
     */
    public function test_preparing_items_resolve_to_the_request_branch(): void
    {
        $order = $this->preparingOrder();

        $this->assertSame('request', $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * @effects delivered_items_are_not_refund_requestable
     */
    public function test_delivered_items_are_not_refund_requestable(): void
    {
        $order = $this->deliveredClaimOrder();

        $this->assertClaimException('claim_refund_not_requestable', fn () => $this->service->resolveBranch($order, $this->payload($order)['items']));
    }

    /**
     * @effects mixed_branches_are_rejected
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
     * @effects an_unknown_option_is_rejected
     */
    public function test_an_unknown_option_is_rejected(): void
    {
        $order = $this->paidClaimOrder();

        $this->assertClaimException('claim_option_not_found', fn () => $this->service->resolveBranch($order, [['order_option_id' => 999999, 'quantity' => 1]]));
        $this->assertClaimException('claim_items_required', fn () => $this->service->resolveBranch($order, []));
    }

    /**
     * @effects auto_branch_wins_when_both_lists_contain_the_same_status
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
     * @effects preview_returns_the_branch_and_the_refund_calculation
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
     * @effects preview_applies_the_quantity_lock_like_the_request
     */
    public function test_preview_applies_the_quantity_lock_like_the_request(): void
    {
        $order = $this->preparingOrder(2);
        $this->service->request($order, $this->payload($order, 2));

        $this->assertClaimException('claim_quantity_locked', fn () => $this->service->previewRefund($order->fresh(['options']), $this->payload($order, 1)['items']));
    }

    /**
     * @effects preview_excludes_the_refund_request_being_edited_from_the_lock
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
     * @effects auto_branch_files_a_refund_request_and_records_the_cancellation
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
     * @effects auto_advance_walks_every_status_step
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
     * @effects refund_branch_files_a_direct_refund_request
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
     * @effects refund_branch_locks_the_quantity_against_a_second_request
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
     * @effects refund_branch_can_auto_approve_by_setting
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
     * @effects refund_branch_stores_the_refund_bank_when_given
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
     * @effects request_rejects_an_inactive_reason
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
     * @effects withdraw_closes_the_request_and_releases_the_quantity
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
     * @effects reject_keeps_the_option_rows_rejected
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
     * @effects withdraw_is_blocked_after_approval
     */
    public function test_withdraw_is_blocked_after_approval(): void
    {
        $order = $this->preparingOrder();
        $refund = $this->service->request($order, $this->payload($order))['refund'];
        $this->service->transition($refund, RefundStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($refund->fresh()));
    }

    /**
     * @effects update_changes_the_reason_before_approval_only
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
     * @effects transition_follows_the_refund_gate_and_refuses_completion
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
     * 헤더 감액은 신청상품 행까지 내려간다.
     *
     * 행 금액은 접수 시점 1회만 기록되므로, 헤더만 고치면 상세가 접수 당시 금액을 계속
     * 말한다 — 실측에서 신청서 155,000 원 ↔ 상품행 합계 151,000 원으로 갈렸다.
     *
     * @effects amount_reduction_reaches_the_option_rows
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
     * @effects amount_change_tracks_the_option_rows_in_both_directions
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
     * @effects reduction_scales_option_rows_when_header_exceeds_their_sum
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
     * @effects non_amount_update_leaves_the_option_rows_untouched
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
     * @effects claim_intake_rejects_empty_items_even_when_the_branch_is_pinned
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
