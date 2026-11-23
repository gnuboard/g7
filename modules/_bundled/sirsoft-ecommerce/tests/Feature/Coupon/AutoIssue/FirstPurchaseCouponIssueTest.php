<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\CouponFirstPurchaseTrigger;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CouponAutoIssueService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 첫구매 쿠폰 자동발급·회수 (#952 P4·P5)
 */
class FirstPurchaseCouponIssueTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /**
     * 발급 시점별로 그 상태 이상에 도달하면 발급하고, 그 전이면 발급하지 않는다.
     *
     * @scenario case=each
     *
     * @effects first_purchase_issued_at_trigger
     *
     * @param  string  $trigger  발급 시점
     * @param  string  $before  시점 도달 전 주문 상태
     * @param  string  $reached  시점에 도달한 주문 상태
     * @return void 반환값 없음
     */
    #[DataProvider('triggerCases')]
    public function test_issues_when_order_reaches_trigger(string $trigger, string $before, string $reached): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => $trigger]);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => $before]);
        $service = app(CouponAutoIssueService::class);

        $service->evaluateFirstPurchase($order->id);
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count(), "before {$trigger}");

        $order->update(['order_status' => $reached]);
        $service->evaluateFirstPurchase($order->id);
        $issue = CouponIssue::query()->where('coupon_id', $coupon->id)->first();
        $this->assertNotNull($issue, "reached {$trigger}");
        $this->assertSame('first_purchase', $issue->auto_issue_key);
        $this->assertSame((int) $order->id, (int) $issue->source_order_id);

        // 같은 주문을 다시 판정해도 한 번이다
        $service->evaluateFirstPurchase($order->id);
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 발급 시점 5종과 각 시점의 도달 전·도달 상태 쌍을 준다.
     *
     * @return array<string, array{string, string, string}> 시점, 도달 전 상태, 도달 상태
     */
    public static function triggerCases(): array
    {
        return [
            'payment_complete' => ['payment_complete', 'pending_payment', 'payment_complete'],
            'preparing' => ['preparing', 'payment_complete', 'shipping_ready'],
            'shipping' => ['shipping', 'shipping_ready', 'shipping'],
            'delivered' => ['delivered', 'shipping', 'delivered'],
            'confirmed' => ['confirmed', 'delivered', 'confirmed'],
        ];
    }

    /**
     * 시점 이상에 먼저 도달한 다른 주문(과거 구매)이 있으면 이번 주문으로는 발급하지 않는다. 비회원 주문은 무시한다.
     *
     * @scenario case=earlier_order_or_guest
     *
     * @effects first_purchase_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_member_with_earlier_purchase_and_guest_order_are_skipped(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::CONFIRMED, 'ordered_at' => now()->subMonth()]);
        $second = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $guest = Order::factory()->forGuest()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $service = app(CouponAutoIssueService::class);

        $service->evaluateFirstPurchase($second->id);
        $service->evaluateFirstPurchase($guest->id);

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 이른 시점 쿠폰은 원천 주문이 전부 취소되면 미사용 쿠폰을 회수하고(발급 수 복원·키 해제), 다음 주문에 다시 발급한다.
     *
     * @scenario case=revoke_and_reissue
     *
     * @effects first_purchase_revoked_and_reissued
     *
     * @return void 반환값 없음
     */
    public function test_revokes_on_cancel_and_reissues_for_next_order(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        $first = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE, 'ordered_at' => now()->subDay()]);
        $service = app(CouponAutoIssueService::class);
        $service->evaluateFirstPurchase($first->id);
        $this->assertSame(1, $coupon->fresh()->issued_count);

        $second = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $first->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $this->assertSame(1, $service->revokeFirstPurchase($first->id));

        $revoked = CouponIssue::query()->where('coupon_id', $coupon->id)->where('source_order_id', $first->id)->first();
        $this->assertSame(CouponIssueRecordStatus::CANCELLED, $revoked->status);
        $this->assertNull($revoked->auto_issue_key);
        $this->assertSame(0, $coupon->fresh()->issued_count);

        $second->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $service->evaluateFirstPurchase($second->id);
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('source_order_id', $second->id)->where('status', 'available')->count());
    }

    /**
     * 사용한 쿠폰은 회수하지 않고, 구매확정 시점 쿠폰은 회수 대상이 아니다.
     *
     * @scenario case=used_or_confirmed
     *
     * @effects first_purchase_not_revoked
     *
     * @return void 반환값 없음
     */
    public function test_used_or_confirmed_trigger_coupons_are_not_revoked(): void
    {
        $early = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $confirmed = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'confirmed']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()]);
        $service = app(CouponAutoIssueService::class);
        $service->evaluateFirstPurchase($order->id);
        $this->assertSame(2, CouponIssue::query()->where('source_order_id', $order->id)->count());

        CouponIssue::query()->where('coupon_id', $early->id)->update(['status' => CouponIssueRecordStatus::USED->value, 'used_at' => now()]);
        $order->update(['order_status' => OrderStatusEnum::RETURNED]);

        $this->assertSame(0, $service->revokeFirstPurchase($order->id));
        $this->assertSame(CouponIssueRecordStatus::USED, CouponIssue::query()->where('coupon_id', $early->id)->first()->status);
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE, CouponIssue::query()->where('coupon_id', $confirmed->id)->first()->status);
    }

    /**
     * 주문 상태 변경 훅(실제 체인)으로도 발급되고, 큐가 늦게 돌아 그 사이 주문이 취소됐으면 발급하지 않는다(현재 DB 상태로 판정).
     *
     * @scenario case=hook_chain
     *
     * @effects first_purchase_issued_at_trigger
     *
     * @return void 반환값 없음
     */
    public function test_hook_chain_and_current_state_judgement(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order->fresh(), 'pending_payment', 'payment_complete');
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->count());

        $other = $this->createUser();
        $stale = Order::factory()->forUser($other)->create(['order_status' => OrderStatusEnum::CANCELLED]);
        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $stale, []);
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $other->id)->count());
    }

    /**
     * PG 결제완료 훅(order.after_payment_complete)이 결제완료 주문으로 오면 그 자리에서 발급한다.
     *
     * @scenario case=pg_payment_complete_hook
     *
     * @effects first_purchase_issued_at_trigger
     *
     * @return void 반환값 없음
     */
    public function test_pg_payment_complete_hook_issues(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order->fresh(), []);

        $issue = CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame((int) $order->id, (int) $issue->source_order_id);
    }

    /**
     * 구매확정 훅(order.after_purchase_confirmed)으로 구매확정 시점 쿠폰이 발급된다.
     *
     * @scenario case=purchase_confirmed_hook
     *
     * @effects first_purchase_issued_at_trigger
     *
     * @return void 반환값 없음
     */
    public function test_purchase_confirmed_hook_issues_confirmed_trigger_coupon(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'confirmed']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::DELIVERED]);

        HookManager::doAction('sirsoft-ecommerce.order.after_purchase_confirmed', $order->fresh());
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count(), '배송완료 상태로는 아직 아니다');

        $order->update(['order_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()]);
        HookManager::doAction('sirsoft-ecommerce.order.after_purchase_confirmed', $order->fresh());

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('source_order_id', $order->id)->count());
    }

    /**
     * 한 회원의 두 주문이 동시에 시점에 도달해도 주문일이 가장 이른 주문 하나로만 한 번 발급한다.
     *
     * @scenario case=two_orders_at_once
     *
     * @effects first_purchase_issued_at_trigger
     *
     * @return void 반환값 없음
     */
    public function test_two_orders_reaching_trigger_together_issue_once_for_earliest(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        $earlier = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE, 'ordered_at' => now()->subMinutes(5)]);
        $later = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE, 'ordered_at' => now()]);
        $service = app(CouponAutoIssueService::class);

        // 늦은 주문의 판정이 먼저 돌아도 이른 주문 몫이라 발급하지 않는다
        $service->evaluateFirstPurchase($later->id);
        $service->evaluateFirstPurchase($earlier->id);
        $service->evaluateFirstPurchase($later->id);

        $issues = CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->get();
        $this->assertCount(1, $issues);
        $this->assertSame((int) $earlier->id, (int) $issues->first()->source_order_id);
        $this->assertSame(1, $coupon->fresh()->issued_count);
    }

    /**
     * 이른 시점 쿠폰은 원천 주문이 반품완료되어도 미사용분을 회수하고, 회수 훅을 발화한다.
     *
     * @scenario case=revoke_on_return
     *
     * @effects first_purchase_revoked_and_reissued
     *
     * @return void 반환값 없음
     */
    public function test_return_revokes_and_fires_revoke_hook(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'delivered']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::DELIVERED]);
        app(CouponAutoIssueService::class)->evaluateFirstPurchase($order->id);
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->count());

        $fired = [];
        $spy = function ($issue, $orderId) use (&$fired) {
            $fired[] = [(int) $issue->id, (int) $orderId];
        };
        HookManager::addAction('sirsoft-ecommerce.coupon.after_auto_issue_revoke', $spy, 99);

        try {
            $order->update(['order_status' => OrderStatusEnum::RETURNED]);
            HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order->fresh(), 'delivered', 'returned');
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.coupon.after_auto_issue_revoke', $spy);
        }

        $issue = CouponIssue::query()->where('coupon_id', $coupon->id)->first();
        $this->assertSame(CouponIssueRecordStatus::CANCELLED, $issue->status);
        $this->assertSame([[(int) $issue->id, (int) $order->id]], $fired);
    }

    /**
     * 부분 취소는 회수하지 않고, 남은 상품까지 취소돼 전체 취소로 바뀐 때(order.after_cancel) 회수한다.
     *
     * 누적 부분 취소가 전체 취소로 바뀌는 판정은 취소 서비스(`shouldConvertToFullCancel`)가 하고, 그때 발화하는 훅은 전체 취소 훅이다.
     *
     * @scenario case=partial_cancel_cumulative_revoke
     *
     * @effects first_purchase_revoked_and_reissued
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_keeps_coupon_until_order_is_fully_cancelled(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE, ['first_purchase_trigger_status' => 'payment_complete']);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        app(CouponAutoIssueService::class)->evaluateFirstPurchase($order->id);

        HookManager::doAction('sirsoft-ecommerce.order.after_partial_cancel', $order->fresh(), ['cancel_type' => 'partial', 'cancel_items' => []], [], null);
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE, CouponIssue::query()->where('coupon_id', $coupon->id)->first()->status, '부분 취소로는 회수하지 않는다');

        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        HookManager::doAction('sirsoft-ecommerce.order.after_cancel', $order->fresh(), ['cancel_type' => 'full', 'cancel_items' => []], [], null);

        $this->assertSame(CouponIssueRecordStatus::CANCELLED, CouponIssue::query()->where('coupon_id', $coupon->id)->first()->status);
        $this->assertSame(0, $coupon->fresh()->issued_count);
    }

    /**
     * 시점 Enum 의 도달 상태 집합은 취소·반품완료를 언제나 뺀다.
     *
     * @scenario case=enum_sets
     *
     * @effects trigger_status_sets
     *
     * @return void 반환값 없음
     */
    public function test_trigger_sets_never_include_terminal_statuses(): void
    {
        foreach (CouponFirstPurchaseTrigger::cases() as $trigger) {
            $this->assertNotContains('cancelled', $trigger->reachedStatusValues());
            $this->assertNotContains('returned', $trigger->reachedStatusValues());
        }
        $this->assertFalse(CouponFirstPurchaseTrigger::CONFIRMED->revocable());
        $this->assertSame(CouponFirstPurchaseTrigger::CONFIRMED, CouponFirstPurchaseTrigger::fromStored(null));
    }
}
