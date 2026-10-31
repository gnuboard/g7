<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\CancellableAmountCalculator;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 수량 잠금 가드 테스트 (S3 · D15)
 *
 * 검증 축:
 * - 활성 클레임(교환·반품 두 축)이 잡아 둔 수량 집계와 종결 건 제외
 * - 자기 자신 제외 인자 (수정 중인 신청서가 자기 수량을 스스로 막지 않는다)
 * - 취소 경로 차단 — 코스 상태 상품과 잔여 초과 수량
 * - 관리자 상태 변경 차단 (D15 후단) — 전이 게이트가 허용하는 코스 → 판매 상태 전이를 가드가 막는다
 * - 클레임이 없는 주문의 취소·상태 변경은 종전과 동일하게 통과한다 (기존 계약 보호)
 */
class ClaimQuantityGuardTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimAdjustmentOrders;

    protected ClaimQuantityGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->guard = app(ClaimQuantityGuard::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 활성 반품 신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  int  $quantity  반품 수량
     * @param  ReturnStatusEnum  $status  신청 상품 상태
     * @return OrderReturn 반품 신청서
     */
    protected function makeReturn(
        Order $order,
        OrderOption $option,
        int $quantity,
        ReturnStatusEnum $status = ReturnStatusEnum::REQUESTED,
    ): OrderReturn {
        $return = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_status' => $status,
        ]);

        OrderReturnOption::factory()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => $status,
            'return_quantity' => $quantity,
            'original_quantity' => (int) $option->quantity,
            'original_option_status' => $option->option_status,
        ]);

        return $return;
    }

    /**
     * 활성 교환 신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  int  $quantity  교환 수량
     * @param  ExchangeStatusEnum  $status  신청 상품 상태
     * @return OrderExchange 교환 신청서
     */
    protected function makeExchange(
        Order $order,
        OrderOption $option,
        int $quantity,
        ExchangeStatusEnum $status = ExchangeStatusEnum::REQUESTED,
    ): OrderExchange {
        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => $status,
        ]);

        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => $status,
            'exchange_quantity' => $quantity,
            'original_quantity' => (int) $option->quantity,
            'original_option_status' => $option->option_status,
        ]);

        return $exchange;
    }

    // ================================================================
    // 집계
    // ================================================================

    /**
     * @effects locked_quantity_counts_an_active_return
     */
    public function test_locked_quantity_counts_an_active_return(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 2);

        $this->assertSame(2, $this->guard->lockedQuantity($option->id));
        $this->assertSame(1, $this->guard->availableQuantity($option));
    }

    /**
     * @effects locked_quantity_ignores_a_finished_return
     */
    public function test_locked_quantity_ignores_a_finished_return(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 2, ReturnStatusEnum::COMPLETED);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id));
        $this->assertSame(3, $this->guard->availableQuantity($option));
    }

    /**
     * @effects locked_quantity_counts_an_active_exchange
     */
    public function test_locked_quantity_counts_an_active_exchange(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeExchange($order, $option, 1);

        $this->assertSame(1, $this->guard->lockedQuantity($option->id));
    }

    /**
     * @effects locked_quantity_sums_both_claim_axes
     */
    public function test_locked_quantity_sums_both_claim_axes(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 1);
        $this->makeExchange($order, $option, 1);

        // 한 축만 세면 교환 진행 중인 수량으로 반품이 접수된다.
        $this->assertSame(2, $this->guard->lockedQuantity($option->id));
        $this->assertSame(1, $this->guard->availableQuantity($option));
    }

    /**
     * @effects locked_quantity_excludes_the_claim_being_edited
     */
    public function test_locked_quantity_excludes_the_claim_being_edited(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $return = $this->makeReturn($order, $option, 2);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id, null, $return->id));
        $this->assertSame(3, $this->guard->availableQuantity($option, null, $return->id));
    }

    /**
     * @effects available_quantity_never_goes_negative
     */
    public function test_available_quantity_never_goes_negative(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 1);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 5);

        $this->assertSame(0, $this->guard->availableQuantity($option));
    }

    // ================================================================
    // 신청 해석
    // ================================================================

    /**
     * @effects resolve_rejects_a_locked_quantity
     */
    public function test_resolve_rejects_a_locked_quantity(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::DELIVERED])->save();

        $this->makeReturn($order, $option, 1);

        $this->assertClaimException('claim_quantity_locked', fn () => $this->guard->resolveClaimItems(
            $order->fresh(['options']),
            [['order_option_id' => $option->id, 'quantity' => 2]],
            ClaimTypeEnum::RETURN,
            [OrderStatusEnum::DELIVERED->value],
        ));
    }

    /**
     * @effects resolve_accepts_the_remaining_quantity
     */
    public function test_resolve_accepts_the_remaining_quantity(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::DELIVERED])->save();

        $this->makeReturn($order, $option, 1);

        $resolved = $this->guard->resolveClaimItems(
            $order->fresh(['options']),
            [['order_option_id' => $option->id, 'quantity' => 1]],
            ClaimTypeEnum::RETURN,
            [OrderStatusEnum::DELIVERED->value],
        );

        $this->assertSame(1, $resolved[$option->id]['quantity']);
    }

    // ================================================================
    // 취소 경로 (§4-4 코스 상태 가드)
    // ================================================================

    /**
     * @effects cancellation_is_blocked_for_an_option_in_a_claim_course
     */
    public function test_cancellation_is_blocked_for_an_option_in_a_claim_course(): void
    {
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS])->save();

        $this->assertClaimException('claim_option_in_course', fn () => $this->guard->assertNotLockedForCancellation($order->fresh(['options']), [
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
        ]));
    }

    /**
     * @effects cancellation_is_blocked_when_quantity_is_locked_by_a_claim
     */
    public function test_cancellation_is_blocked_when_quantity_is_locked_by_a_claim(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 1);

        $this->assertClaimException('claim_quantity_locked', fn () => $this->guard->assertNotLockedForCancellation($order->fresh(['options']), [
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]));
    }

    /**
     * @effects cancellation_service_reports_the_claim_lock_as_a_cancel_failure
     */
    public function test_cancellation_service_reports_the_claim_lock_as_a_cancel_failure(): void
    {
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS])->save();

        // 취소 경로의 응답 계약(422)을 유지하려면 취소 도메인 예외로 도착해야 한다 —
        // 클레임 예외가 그대로 새면 컨트롤러의 일반 catch 로 떨어져 500 이 된다.
        $this->expectException(OrderCancellationException::class);
        app(OrderCancellationService::class)->cancelOrderOptions(
            $order->fresh(['options', 'payment', 'shippings']),
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            'changed_mind',
            null,
            null,
            false,
        );
    }

    /**
     * @effects cancellation_without_any_claim_still_succeeds
     */
    public function test_cancellation_without_any_claim_still_succeeds(): void
    {
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();

        $result = app(OrderCancellationService::class)->cancelOrderOptions(
            $order->fresh(['options', 'payment', 'shippings']),
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            'changed_mind',
            null,
            null,
            false,
        );

        $this->assertNotNull($result->orderCancel);
        $this->assertSame(OrderStatusEnum::CANCELLED, $option->fresh()->option_status);
    }

    // ───────────────────────────────────────────────
    // 관리자 상태 변경 차단 (D15 후단)
    // ───────────────────────────────────────────────

    /**
     * @effects claim_course_status_is_resolved_from_both_enum_and_raw_value
     */
    public function test_claim_course_status_is_resolved_from_both_enum_and_raw_value(): void
    {
        // 관리자 경로는 모델 캐스트를 거친 Enum 과 스냅샷 배열의 원시 문자열을 모두 넘긴다.
        $this->assertSame(
            OrderStatusEnum::RETURN_IN_PROGRESS,
            $this->guard->resolveClaimCourseStatus(OrderStatusEnum::RETURN_IN_PROGRESS),
        );
        $this->assertSame(
            OrderStatusEnum::EXCHANGE_IN_PROGRESS,
            $this->guard->resolveClaimCourseStatus(OrderStatusEnum::EXCHANGE_IN_PROGRESS->value),
        );
        $this->assertNull($this->guard->resolveClaimCourseStatus(OrderStatusEnum::DELIVERED));
        $this->assertNull($this->guard->resolveClaimCourseStatus(null));
        $this->assertNull($this->guard->resolveClaimCourseStatus(''));
    }

    /**
     * @effects admin_status_change_is_blocked_for_claim_course_items
     */
    public function test_admin_status_change_is_blocked_for_claim_course_items(): void
    {
        $this->assertClaimException('claim_option_in_course_status_change', fn () => $this->guard->assertNotInClaimCourse([
            OrderStatusEnum::DELIVERED->value,
            OrderStatusEnum::RETURN_IN_PROGRESS->value,
        ]));
    }

    /**
     * @effects admin_status_change_passes_for_non_claim_items
     */
    public function test_admin_status_change_passes_for_non_claim_items(): void
    {
        // 판정 메서드는 void 라 "예외 없음" 이 유일한 관측이다 — 항상 참인 단언 대신
        // 단언 0건을 명시한다.
        $this->expectNotToPerformAssertions();

        $this->guard->assertNotInClaimCourse([
            OrderStatusEnum::DELIVERED->value,
            OrderStatusEnum::SHIPPING->value,
        ]);
    }

    /**
     * @effects course_status_cannot_be_assigned_as_a_target
     */
    public function test_course_status_cannot_be_assigned_as_a_target(): void
    {
        // 코스 상태는 클레임 서비스만 기록한다 — 목표 상태로 지정하는 것은 소스 가드와 별개로 막는다.
        $this->assertClaimException('claim_course_status_not_assignable', fn () => $this->guard->assertNotClaimCourseTarget(OrderStatusEnum::RETURN_IN_PROGRESS));
        $this->assertClaimException('claim_course_status_not_assignable', fn () => $this->guard->assertNotClaimCourseTarget(OrderStatusEnum::EXCHANGED));
    }

    /**
     * @effects admin_bulk_status_change_cannot_assign_a_course_status
     */
    public function test_admin_bulk_status_change_cannot_assign_a_course_status(): void
    {
        // FormRequest 는 adminAssignableValues 로 목표를 막지만, 내부·훅 호출은 서비스가 막아야
        // 신청서 없는 코스 상태가 생기지 않는다 (확정 2026-08-22 6차 ③).
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::DELIVERED])->save();

        $this->assertClaimException('claim_course_status_not_assignable', fn () => app(OrderOptionService::class)->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::RETURN_IN_PROGRESS,
            $order->id,
        ));

        $this->assertSame(OrderStatusEnum::DELIVERED, $option->fresh()->option_status);
    }

    /**
     * @effects admin_bulk_status_change_cannot_pull_an_item_out_of_a_return_course
     */
    public function test_admin_bulk_status_change_cannot_pull_an_item_out_of_a_return_course(): void
    {
        // 코스 진행 2종은 반려·철회 복귀를 위해 전이 게이트가 판매 상태를 허용한다. 그 허용을
        // 관리자 일괄 변경이 그대로 읽으면 진행 중인 반품의 상품이 코스 밖으로 빠져나가고,
        // 그 행은 이후 복귀 처리에서 조용히 제외된다.
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS])->save();

        // 전이 게이트 자체는 이 전이를 허용한다 — 가드가 유일한 차단 지점임을 대조로 고정한다.
        $this->assertTrue(OrderStatusEnum::RETURN_IN_PROGRESS->canTransitionTo(OrderStatusEnum::DELIVERED));

        $this->assertClaimException('claim_option_in_course_status_change', fn () => app(OrderOptionService::class)->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::DELIVERED,
            $order->id,
        ));
    }

    /**
     * @effects admin_bulk_status_change_still_works_for_items_without_a_claim
     */
    public function test_admin_bulk_status_change_still_works_for_items_without_a_claim(): void
    {
        $order = $this->createSingleItemClaimOrder();
        $option = $order->options->first();
        $option->forceFill(['option_status' => OrderStatusEnum::SHIPPING])->save();

        app(OrderOptionService::class)->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => 1]],
            OrderStatusEnum::DELIVERED,
            $order->id,
        );

        $this->assertSame(OrderStatusEnum::DELIVERED, $option->fresh()->option_status);
    }

    /**
     * @effects locked_quantity_counts_a_live_direct_refund_request
     */
    public function test_locked_quantity_counts_a_live_direct_refund_request(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeDirectRefund($order, $option, 2);

        // 직접 환불신청서는 원 주문옵션을 판매 상태 그대로 두므로 수량 합산 가드만이 이중 신청을 막는다.
        $this->assertSame(2, $this->guard->lockedQuantity($option->id));
        $this->assertSame(1, $this->guard->availableQuantity($option));
    }

    /**
     * @effects locked_quantity_ignores_a_finished_direct_refund_request
     */
    public function test_locked_quantity_ignores_a_finished_direct_refund_request(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeDirectRefund($order, $option, 2, RefundStatusEnum::WITHDRAWN);
        $this->makeDirectRefund($order, $option, 1, RefundStatusEnum::REJECTED);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id));
    }

    /**
     * @effects locked_quantity_ignores_refund_requests_from_other_sources
     */
    public function test_locked_quantity_ignores_refund_requests_from_other_sources(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        // 취소·반품·교환 발 환불신청서의 원 주문옵션은 이미 취소·코스 상태라 이 축에서 세지 않는다.
        $this->makeDirectRefund($order, $option, 2, RefundStatusEnum::REQUESTED, RefundSourceTypeEnum::CANCEL);
        $this->makeDirectRefund($order, $option, 1, RefundStatusEnum::REQUESTED, RefundSourceTypeEnum::RETURN);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id));
    }

    /**
     * @effects locked_quantity_sums_all_three_claim_axes
     */
    public function test_locked_quantity_sums_all_three_claim_axes(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 5);
        $option = $order->options->first();

        $this->makeReturn($order, $option, 1);
        $this->makeExchange($order, $option, 1);
        $this->makeDirectRefund($order, $option, 2);

        $this->assertSame(4, $this->guard->lockedQuantity($option->id));
        $this->assertSame(1, $this->guard->availableQuantity($option));
    }

    /**
     * @effects locked_quantity_excludes_the_direct_refund_being_edited
     */
    public function test_locked_quantity_excludes_the_direct_refund_being_edited(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $refund = $this->makeDirectRefund($order, $option, 2);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id, null, null, $refund->id));
        $this->assertSame(3, $this->guard->availableQuantity($option, null, null, $refund->id));
    }

    /**
     * @effects resolve_rejects_a_quantity_locked_by_a_direct_refund_request
     */
    public function test_resolve_rejects_a_quantity_locked_by_a_direct_refund_request(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $this->makeDirectRefund($order, $option, 2);

        $this->assertClaimException('claim_quantity_locked', fn () => $this->guard->resolveClaimItems(
            $order,
            [['order_option_id' => $option->id, 'quantity' => 2]],
            ClaimTypeEnum::RETURN,
            [OrderStatusEnum::PAYMENT_COMPLETE->value],
        ));
    }

    /**
     * 헤더가 열려 있어도 **상품 행이 반려되면 그 수량 잠금은 풀린다**.
     *
     * 환불 축만 헤더 상태로 종결분을 걸렀다 — 상품 단위로 판단이 갈릴 수 있게 되면서,
     * 반려된 상품의 수량이 계속 잠긴 채로 남았다. 고객에게는 "신청 가능 수량 부족" 만
     * 보이고, 왜 부족한지는 화면 어디에도 드러나지 않는다.
     *
     * @effects rejected_refund_item_releases_its_quantity_lock
     */
    public function test_a_rejected_refund_item_releases_its_quantity_lock(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $refund = $this->makeDirectRefund($order, $option, 2);

        $this->assertSame(2, $this->guard->lockedQuantity($option->id), '접수 직후에는 2개가 잠긴다.');

        // 운영자가 그 상품을 반려했다 — 헤더는 아직 열려 있다.
        $refund->refundOptions()->update(['option_status' => RefundStatusEnum::REJECTED->value]);

        $this->assertSame(0, $this->guard->lockedQuantity($option->id), '반려된 상품의 수량은 더 이상 잠기지 않는다.');
        $this->assertSame(3, $this->guard->availableQuantity($option));
    }

    /**
     * 승인 계열 행만 잠금에 남는다 — 부분 승인이 잔여 수량을 정확히 돌려준다.
     *
     * @effects approved_refund_item_keeps_only_its_own_quantity_locked
     */
    public function test_an_approved_refund_item_keeps_only_its_own_quantity_locked(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $refund = $this->makeDirectRefund($order, $option, 3);

        // 3개 중 1개만 승인하고 나머지를 반려한 모습(분할 뒤의 상태)을 만든다.
        $rows = $refund->refundOptions()->get();
        $rows->first()->update(['quantity' => 1, 'option_status' => RefundStatusEnum::APPROVED->value]);

        $refund->refundOptions()->create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => RefundStatusEnum::REJECTED->value,
            'quantity' => 2,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => (float) $option->unit_price * 2,
            'discount_amount' => 0,
            'refund_amount' => 0,
        ]);

        $this->assertSame(1, $this->guard->lockedQuantity($option->id), '승인분 1개만 잠긴다.');
        $this->assertSame(2, $this->guard->availableQuantity($option), '반려분 2개는 다시 신청할 수 있다.');
    }

    /**
     * 취소 가능 금액(D19)이 **반려분을 세지 않는다**.
     *
     * 반려 행을 포함하면 상한이 그만큼 넓어져 과다 환불이 가드를 통과한다 — 저장·실행
     * 양쪽이 같은 방향으로 느슨해지므로 어느 쪽에서도 예외가 나지 않는다.
     *
     * @effects cancellable_amount_excludes_rejected_items
     */
    public function test_cancellable_amount_excludes_rejected_items(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $option = $order->options->first();

        $refund = $this->makeDirectRefund($order, $option, 3);
        $calculator = app(CancellableAmountCalculator::class);

        $all = $calculator->calculateForRefund($order->fresh(['options', 'shippings', 'payments']), $refund->fresh(['refundOptions']));

        // 3개 중 1개만 승인 — 나머지는 반려.
        $rows = $refund->refundOptions()->get();
        $rows->first()->update(['quantity' => 1, 'option_status' => RefundStatusEnum::APPROVED->value]);
        $refund->refundOptions()->create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => RefundStatusEnum::REJECTED->value,
            'quantity' => 2,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => (float) $option->unit_price * 2,
            'discount_amount' => 0,
            'refund_amount' => 0,
        ]);

        $approvedOnly = $calculator->calculateForRefund(
            $order->fresh(['options', 'shippings', 'payments']),
            $refund->fresh(['refundOptions'])
        );

        $this->assertLessThan(
            $all->amount,
            $approvedOnly->amount,
            '반려분을 뺀 상한이 전건 상한보다 작아야 합니다.'
        );
    }
}
