<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품·수량 단위 전이 테스트 (§4-2 · §4-5 — R5 · R11 · R12)
 *
 * 검증 축:
 * - **R5** 상품 단위 반려의 원 주문옵션 복귀가 **그 수량만** 되돌린다. 전량으로 되돌리면
 *   남은 진행분이 코스 밖으로 빠져나가고, 이후 취소가 코스 가드를 통과해 같은 상품이 두 번
 *   환불된다 — 오류도 로그도 없다.
 * - **R12** 환불 헤더 동기화가 `processing`·`completed` 로는 따라가지 않는다. 둘 다 환불
 *   실행이 소유하는 상태라, 따라가면 돈이 움직이지 않았는데 신청서가 처리중으로 보인다.
 * - **R11** 반려 행이 환불액을 들고 남지 않는다. 남으면 헤더 합계는 맞는데 D19 선점액
 *   집계가 그 돈을 계속 센다.
 * - 금액 합 보존 — 반려분만큼만 줄고, 남은 행이 그 몫을 그대로 들고 있다.
 * - 활동로그 전/후 라벨이 비지 않는다(상품 행 Enum 으로 해석되는지).
 */
class ClaimItemTransitionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 반품 코스에 올라간 주문옵션과 그 신청서를 만듭니다.
     *
     * @param  int  $quantity  주문 수량
     * @return array{0: Order, 1: OrderOption, 2: OrderReturn, 3: OrderReturnOption} 주문·주문옵션·신청서·신청상품
     */
    protected function makeReturnInCourse(int $quantity = 3): array
    {
        $order = $this->createSingleItemClaimOrder(29000, $quantity);
        $option = $order->options->first();
        $option->update(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS]);

        $return = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_status' => ReturnStatusEnum::REQUESTED,
        ]);

        $item = OrderReturnOption::factory()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => ReturnStatusEnum::REQUESTED,
            'return_quantity' => $quantity,
            'original_quantity' => $quantity,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => 29000,
            'subtotal_amount' => 29000 * $quantity,
        ]);

        return [$order, $option->refresh(), $return, $item];
    }

    /**
     * 상품 행이 여럿인 직접 환불신청서를 **접수 경로 그대로** 만듭니다.
     *
     * 헤더 금액을 손으로 세팅하지 않고 접수가 계산한 주문을 그대로 쓰는 이유는 D19 상한
     * 때문이다 — 상한은 주문의 결제·배송·옵션 상태에서 나오므로, 모형 주문에 금액만 얹으면
     * 상한이 0 이 되어 재계산이 상한 위반으로 막힌다(테스트가 계약이 아니라 픽스처를 잰다).
     *
     * 접수가 만든 단일 행을 지정한 수량·금액으로 갈라 놓는다 — 수량 분할이 만드는 모양이다.
     *
     * @param  array<int, array{quantity: int, amount: float}>  $rows  상품 행 정의
     * @return array{0: Order, 1: OrderRefund} 주문과 환불신청서
     */
    protected function makeRefundWithRows(array $rows): array
    {
        $quantity = array_sum(array_column($rows, 'quantity'));
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, $quantity, $policy);
        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);
        $option = $order->options->first();

        $refund = app(OrderRefundRequestService::class)->request($order, [
            'items' => [['order_option_id' => $option->id, 'quantity' => $quantity]],
            'reason_code' => 'changed_mind',
            'reason_detail' => '단순 변심',
            'requested_by' => $order->user_id,
        ])['refund'];

        $refund->refundOptions()->delete();

        foreach ($rows as $row) {
            OrderRefundOption::create([
                'order_refund_id' => $refund->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => RefundStatusEnum::REQUESTED,
                'quantity' => $row['quantity'],
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => $row['amount'],
                'discount_amount' => 0,
                'refund_amount' => $row['amount'],
            ]);
        }

        $refund->update(['refund_amount' => array_sum(array_column($rows, 'amount'))]);

        return [$order, $refund->fresh(['refundOptions'])];
    }

    // ================================================================
    // R5 — 복귀 수량
    // ================================================================

    /**
     * @effects item_rejection_reverts_only_its_own_quantity
     */
    public function test_item_rejection_reverts_only_its_own_quantity(): void
    {
        [$order, $option, $return, $item] = $this->makeReturnInCourse(3);

        // 3개 중 2개만 반려한다 — 나머지 1개는 아직 반품 코스에 남아야 한다.
        app(OrderReturnService::class)->transitionOption(
            $return,
            [['id' => $item->id, 'quantity' => 2]],
            ReturnStatusEnum::REJECTED,
        );

        $reverted = OrderOption::where('order_id', $order->id)
            ->where('option_status', OrderStatusEnum::DELIVERED)
            ->sum('quantity');

        $stillInCourse = OrderOption::where('order_id', $order->id)
            ->where('option_status', OrderStatusEnum::RETURN_IN_PROGRESS)
            ->sum('quantity');

        $this->assertSame(2, (int) $reverted, '반려한 수량만 신청 직전 상태로 돌아가야 한다');
        $this->assertSame(1, (int) $stillInCourse, '남은 진행분이 코스 밖으로 빠지면 같은 상품이 두 번 환불된다');
    }

    /**
     * 부분 반려가 **잠금 수량을 그만큼 푼다.**
     *
     * 반려는 "이 수량은 안 받는다" 는 뜻이므로 그 수량은 다시 신청할 수 있어야 한다.
     * 잠금이 안 풀리면 고객은 「신청 가능 수량 부족」만 보게 되고, 그 문구는 원인을
     * 말해 주지 않는다 — 운영자도 고객도 왜 막혔는지 알 수 없다(R4).
     *
     * @effects item_rejection_frees_the_locked_quantity
     */
    public function test_item_rejection_frees_the_locked_quantity(): void
    {
        [, $option, $return, $item] = $this->makeReturnInCourse(3);

        $guard = app(ClaimQuantityGuard::class);
        $before = $guard->lockedQuantity($option->id);

        app(OrderReturnService::class)->transitionOption(
            $return,
            [['id' => $item->id, 'quantity' => 2]],
            ReturnStatusEnum::REJECTED,
        );

        // **잠금 수량**으로 잰다. 반려는 주문상품 행도 함께 가르므로(`revertOrderOptionFor`)
        // `availableQuantity()` 를 원본 행 하나로 재면 분모(`option->quantity`)까지 같이
        // 줄어들어 "잠금이 풀렸는지" 를 가릴 수 없다. 신청서 행은 둘로 갈려도 둘 다 같은
        // `order_option_id` 를 가리키므로 이 집계가 그 축을 정확히 잰다.
        $after = $guard->lockedQuantity($option->id);

        $this->assertSame(3, $before, '신청 전량이 잠겨 있어야 이 축이 성립한다');
        $this->assertSame(1, $after, '반려한 2개의 잠금이 풀리고 남은 1개만 잠겨 있어야 한다');
    }

    /**
     * @effects item_rejection_splits_the_claim_row
     */
    public function test_item_rejection_splits_the_claim_row(): void
    {
        [, , $return, $item] = $this->makeReturnInCourse(3);

        $result = app(OrderReturnService::class)->transitionOption(
            $return,
            [['id' => $item->id, 'quantity' => 2]],
            ReturnStatusEnum::REJECTED,
        );

        // 운영자가 체크한 행 ID 가 `succeeded` 에 그대로 돌아와야 한다(원본 = 전이분).
        $this->assertSame([$item->id], $result['succeeded']);
        $this->assertCount(1, $result['split']);
        $this->assertSame($item->id, $result['split'][0]['id']);
        $this->assertSame(2, $result['split'][0]['quantity']);
        $this->assertSame(1, $result['split'][0]['remainder_quantity']);

        $rows = OrderReturnOption::where('order_return_id', $return->id)->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(ReturnStatusEnum::REJECTED, $rows[0]->option_status);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $rows[1]->option_status);
        $this->assertEqualsWithDelta(
            29000.0 * 3,
            (float) $rows[0]->subtotal_amount + (float) $rows[1]->subtotal_amount,
            0.01
        );
    }

    /**
     * @effects full_quantity_transition_keeps_the_single_row
     */
    public function test_full_quantity_transition_keeps_the_single_row(): void
    {
        [$order, , $return, $item] = $this->makeReturnInCourse(3);

        // 수량을 싣지 않는 종전 호출부의 동작이 그대로여야 한다.
        app(OrderReturnService::class)->transitionOption($return, [$item->id], ReturnStatusEnum::REJECTED);

        $this->assertSame(1, OrderReturnOption::where('order_return_id', $return->id)->count());
        $this->assertSame(
            3,
            (int) OrderOption::where('order_id', $order->id)
                ->where('option_status', OrderStatusEnum::DELIVERED)
                ->sum('quantity')
        );
    }

    // ================================================================
    // R12 — 환불 헤더 동기화
    // ================================================================

    /**
     * @effects refund_header_follows_items_to_approved
     */
    public function test_refund_header_follows_items_to_approved(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $option = $order->options->first();
        $refund = $this->makeDirectRefund($order, $option, 2);
        $item = $refund->refundOptions()->first();

        app(OrderRefundRequestService::class)->transitionOption(
            $refund,
            [$item->id],
            RefundStatusEnum::APPROVED,
        );

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * @effects refund_header_never_follows_items_into_execution_states
     */
    public function test_refund_header_never_follows_items_into_execution_states(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $option = $order->options->first();
        $refund = $this->makeDirectRefund($order, $option, 2);
        $refund->update(['refund_status' => RefundStatusEnum::APPROVED]);

        // 상품 행이 처리중에 모여 있어도 헤더는 따라가지 않는다 — 돈이 움직이지 않았는데
        // 신청서가 처리중으로 보이면 운영자는 PG 요청이 나간 줄 안다.
        $refund->refundOptions()->update(['option_status' => RefundStatusEnum::PROCESSING->value]);

        $service = app(OrderRefundRequestService::class);
        $method = new \ReflectionMethod($service, 'syncHeaderFromOptions');
        $method->invoke($service, $refund->fresh(['refundOptions']), null);

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);

        $refund->refundOptions()->update(['option_status' => RefundStatusEnum::COMPLETED->value]);
        $method->invoke($service, $refund->fresh(['refundOptions']), null);

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * @effects refund_item_completion_is_refused_outside_execution
     */
    public function test_refund_item_completion_is_refused_outside_execution(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 2);
        $refund = $this->makeDirectRefund($order, $order->options->first(), 2);

        $this->expectExceptionMessage(__('sirsoft-ecommerce::exceptions.claim_completion_flow_required'));

        app(AdminClaimService::class)->transitionOptions($refund, [], 'completed');
    }

    // ================================================================
    // R11 · §4-5 — 금액 재계산
    // ================================================================

    /**
     * @effects rejected_refund_row_keeps_no_money
     */
    public function test_rejected_refund_row_keeps_no_money(): void
    {
        [, $refund] = $this->makeRefundWithRows([
            ['quantity' => 2, 'amount' => 20000.0],
            ['quantity' => 1, 'amount' => 10000.0],
        ]);

        $rows = $refund->refundOptions->sortBy('id')->values();

        app(AdminClaimService::class)->transitionOptions(
            $refund,
            [$rows[1]->id],
            RefundStatusEnum::REJECTED->value,
        );

        $after = $refund->fresh(['refundOptions'])->refundOptions->sortBy('id')->values();

        // 반려 행이 돈을 들고 남으면 D19 선점액 집계가 그것을 계속 센다 — 헤더 합계는
        // 맞으므로 어디에도 드러나지 않는다.
        $this->assertEqualsWithDelta(0.0, (float) $after[1]->refund_amount, 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $after[0]->refund_amount, 0.01, '승인분은 자기 몫을 그대로 들고 있어야 한다');
        $this->assertEqualsWithDelta(20000.0, (float) $refund->fresh()->refund_amount, 0.01, '헤더는 반려분만큼만 줄어야 한다');
    }

    /**
     * @effects partial_approval_leaves_the_header_amount_untouched
     */
    public function test_partial_approval_leaves_the_header_amount_untouched(): void
    {
        [, $refund] = $this->makeRefundWithRows([
            ['quantity' => 2, 'amount' => 20000.0],
            ['quantity' => 1, 'amount' => 10000.0],
        ]);

        $rows = $refund->refundOptions->sortBy('id')->values();

        // 승인만 했고 아무것도 반려하지 않았다 — 헤더가 줄면 미판단 행의 몫이 사라진다.
        app(AdminClaimService::class)->transitionOptions(
            $refund,
            [$rows[0]->id],
            RefundStatusEnum::APPROVED->value,
        );

        $this->assertEqualsWithDelta(30000.0, (float) $refund->fresh()->refund_amount, 0.01);
    }

    /**
     * @effects successive_rejections_do_not_double_shrink_the_header
     */
    public function test_successive_rejections_do_not_double_shrink_the_header(): void
    {
        [, $refund] = $this->makeRefundWithRows([
            ['quantity' => 1, 'amount' => 10000.0],
            ['quantity' => 1, 'amount' => 10000.0],
            ['quantity' => 1, 'amount' => 10000.0],
        ]);

        $rows = $refund->refundOptions->sortBy('id')->values();
        $service = app(AdminClaimService::class);

        $service->transitionOptions($refund, [$rows[2]->id], RefundStatusEnum::REJECTED->value);
        $this->assertEqualsWithDelta(20000.0, (float) $refund->fresh()->refund_amount, 0.01);

        // 두 번째 라운드의 분모가 전건이면 앞 라운드에서 이미 빠진 몫을 다시 빼서
        // 10,000 이 아니라 6,666 이 된다 — 합계가 언제나 맞아떨어져 드러나지 않는다.
        $service->transitionOptions($refund->fresh(['refundOptions']), [$rows[1]->id], RefundStatusEnum::REJECTED->value);
        $this->assertEqualsWithDelta(10000.0, (float) $refund->fresh()->refund_amount, 0.01);

        $after = $refund->fresh(['refundOptions'])->refundOptions->sortBy('id')->values();
        $this->assertEqualsWithDelta(10000.0, (float) $after[0]->refund_amount, 0.01);
    }

    // ================================================================
    // 부분 처리 안내 (§5-5 — S11)
    // ================================================================

    /**
     * 헤더 상태는 상품이 갈리면 움직이지 않는다 — 그때 기존 상태 알림이 발화하지 않으므로
     * 고객은 자기 신청이 어떻게 갈렸는지 알 방법이 없다.
     *
     * @effects partial_decision_announces_once_per_bulk_apply
     */
    public function test_partial_decision_announces_once_per_bulk_apply(): void
    {
        $order = $this->deliveredClaimOrder(29000, 3);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $fired = [];
        HookManager::addAction(
            ClaimNotificationHooks::PARTIALLY_PROCESSED,
            function ($claim, array $summary = []) use (&$fired) {
                $fired[] = $summary;
            },
            5
        );

        // 3개 중 2개만 반려 — 남은 1개는 계속 검토 중이다.
        app(AdminClaimService::class)->transitionOptions(
            $return,
            [['id' => $item->id, 'quantity' => 2]],
            ReturnStatusEnum::REJECTED->value,
        );

        // 건수가 아니라 **수량**이다 — 분할로 행이 둘이 됐다고 "2개 중 1개" 라고 말하면
        // 고객이 신청한 단위(3개)와 어긋난다.
        $this->assertCount(1, $fired, '일괄 적용 1회에 안내는 1통이다');
        $this->assertSame('3', $fired[0]['total_count']);
        $this->assertSame('2', $fired[0]['processed_count']);
        $this->assertSame('1', $fired[0]['remaining_count']);
        $this->assertSame('2', $fired[0]['rejected_count']);
        $this->assertNotSame('', $fired[0]['decision_label']);
    }

    /**
     * 전건이 같은 결정으로 모이면 헤더가 따라 움직이고 기존 상태 알림이 같은 사실을 말한다 —
     * 여기서 한 통을 더 보내면 고객이 같은 소식을 두 번 받는다.
     *
     * @effects uniform_decision_does_not_announce_partial_processing
     */
    public function test_uniform_decision_does_not_announce_partial_processing(): void
    {
        [, , $return, $item] = $this->makeReturnInCourse(3);

        $fired = 0;
        HookManager::addAction(
            ClaimNotificationHooks::PARTIALLY_PROCESSED,
            function () use (&$fired) {
                $fired++;
            },
            5
        );

        app(AdminClaimService::class)->transitionOptions(
            $return,
            [$item->id],
            ReturnStatusEnum::REJECTED->value,
        );

        $this->assertSame(0, $fired);
        $this->assertSame(ReturnStatusEnum::REJECTED, $return->fresh()->return_status);
    }

    /**
     * 요약 숫자는 **훅 인자**로 실린다 — 큐가 잡을 때 신청서에서 다시 세면 과거 사건의
     * 안내에 지금 숫자가 실린다.
     *
     * @effects partial_processing_summary_reaches_the_notification_data
     */
    public function test_partial_processing_summary_reaches_the_notification_data(): void
    {
        $order = $this->deliveredClaimOrder(29000, 1);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $extracted = app(ClaimNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'claim_partially_processed',
            [$return, ['total_count' => 3, 'processed_count' => 2, 'remaining_count' => 1, 'decision_label' => '반품반려']],
        );

        $this->assertSame('3', $extracted['data']['total_count']);
        $this->assertSame('2', $extracted['data']['processed_count']);
        $this->assertSame('1', $extracted['data']['remaining_count']);
        $this->assertSame('반품반려', $extracted['data']['decision_label']);
        // 기존 클레임 변수가 함께 실린다 — 요약만 실으면 본문의 신청번호·링크가 빈다.
        $this->assertArrayHasKey('claim_number', $extracted['data']);
        $this->assertArrayHasKey('claim_url', $extracted['data']);
    }

    // ================================================================
    // 활동로그
    // ================================================================

    /**
     * @effects item_transition_logs_both_labels_and_the_split_quantity
     */
    public function test_item_transition_logs_both_labels_and_the_split_quantity(): void
    {
        [, , $return, $item] = $this->makeReturnInCourse(3);

        $captured = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.return.option_status_changed',
            function ($claim, array $changes = []) use (&$captured) {
                $captured = $changes;
            },
            5
        );

        app(OrderReturnService::class)->transitionOption(
            $return,
            [['id' => $item->id, 'quantity' => 2]],
            ReturnStatusEnum::REJECTED,
        );

        $this->assertCount(1, $captured);
        $this->assertSame(ReturnStatusEnum::REQUESTED->value, $captured[0]['previous']);
        $this->assertSame(ReturnStatusEnum::REJECTED->value, $captured[0]['new']);
        $this->assertNotSame('', (string) $captured[0]['previous'], '전 라벨이 비면 활동로그 표가 빈칸으로 나온다');
        $this->assertSame(2, $captured[0]['quantity']);
        $this->assertArrayHasKey('remainder_option_id', $captured[0]);
    }

    /**
     * 위 단언의 환불 동형.
     *
     * R7 이 겨냥한 유형이 바로 환불이다 — `RefundOptionStatusEnum` 을 `RefundStatusEnum`
     * 으로 합칠 때 참조가 한 곳이라도 남으면 활동로그 큐 잡이 **클래스 부재로 죽고
     * 그 전이의 로그만 사라진다**. 화면 어디에도 드러나지 않는 종류의 고장이라,
     * 그것을 재는 단언이 반품 축에만 있으면 정작 위험한 유형이 비게 된다.
     *
     * @effects refund_item_transition_logs_both_labels_and_the_split_quantity
     */
    public function test_refund_item_transition_logs_both_labels_and_the_split_quantity(): void
    {
        [, $refund] = $this->makeRefundWithRows([
            ['quantity' => 3, 'amount' => 30000.0],
        ]);

        $item = $refund->refundOptions->first();

        $captured = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.option_status_changed',
            function ($claim, array $changes = []) use (&$captured) {
                $captured = $changes;
            },
            5
        );

        app(AdminClaimService::class)->transitionOptions(
            $refund,
            [['id' => $item->id, 'quantity' => 2]],
            RefundStatusEnum::REJECTED->value,
        );

        $this->assertCount(1, $captured);
        $this->assertSame(RefundStatusEnum::REQUESTED->value, $captured[0]['previous']);
        $this->assertSame(RefundStatusEnum::REJECTED->value, $captured[0]['new']);
        $this->assertNotSame('', (string) $captured[0]['previous'], '전 라벨이 비면 활동로그 표가 빈칸으로 나온다');
        $this->assertNotSame('', (string) $captured[0]['new'], '후 라벨이 비면 무엇으로 바뀌었는지가 로그에 남지 않는다');
        $this->assertSame(2, $captured[0]['quantity']);
        $this->assertArrayHasKey('remainder_option_id', $captured[0]);
    }
}
