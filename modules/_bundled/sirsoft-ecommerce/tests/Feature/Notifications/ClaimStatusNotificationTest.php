<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notifications;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상태전이 → 알림 훅 매핑 테스트 (S7 / §4-8)
 *
 * 검증 축:
 * - 접수·전이가 **그 상태의 알림 훅 하나만** 발화한다(다른 28종은 조용해야 한다)
 * - 철회는 고객 알림이 아니라 관리자 수신 훅으로 간다
 * - 알림 대상이 아닌 상태(환불 `processing`)와 미전이 재호출은 아무것도 발화하지 않는다
 * - 매핑 기준은 전이 시점 목표 상태다 — 모델의 "지금" 상태가 아니다(큐 지연 안전성)
 */
class ClaimStatusNotificationTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /** @var array<int, string> 이번 테스트에서 발화된 알림 훅 */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->fired = [];

        foreach ($this->allNotifyHooks() as $hook) {
            HookManager::addAction($hook, function () use ($hook) {
                $this->fired[] = $hook;
            }, 1);
        }
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 29종 알림 훅 전체 목록을 반환합니다.
     *
     * @return array<int, string> 훅 이름
     */
    private function allNotifyHooks(): array
    {
        $hooks = [ClaimStatusNotificationListener::WITHDRAWN_HOOK];

        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach (array_keys($keys) as $status) {
                $hooks[] = ClaimStatusNotificationListener::notifyHookFor($claimType, $status);
            }
        }

        return $hooks;
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

    private function requestedExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder();

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * @effects request_fires_only_the_requested_notification_hook
     */
    public function test_requesting_an_exchange_fires_only_the_requested_notification_hook(): void
    {
        $this->requestedExchange();

        $this->assertSame(
            ['sirsoft-ecommerce.claim.exchange.notify_requested'],
            $this->fired,
            '접수는 그 상태의 알림 훅 하나만 발화해야 합니다.'
        );
    }

    /**
     * @effects transition_fires_only_the_mapped_notification_hook
     */
    public function test_approving_a_return_fires_the_mapped_notification_hook(): void
    {
        $return = $this->requestedReturn();
        $this->fired = [];

        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);

        $this->assertSame(
            ['sirsoft-ecommerce.claim.return.notify_approved'],
            $this->fired
        );
    }

    /**
     * @effects withdrawal_fires_admin_hook_and_no_customer_hook
     */
    public function test_withdrawing_a_claim_fires_the_admin_hook_and_no_customer_hook(): void
    {
        $return = $this->requestedReturn();
        $this->fired = [];

        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::WITHDRAWN);

        $this->assertSame(
            [ClaimStatusNotificationListener::WITHDRAWN_HOOK],
            $this->fired,
            '철회는 신청자에게 되돌려 보내지 않고 관리자 수신 알림 1종만 발화합니다.'
        );
    }

    /**
     * @effects non_customer_visible_status_fires_nothing
     */
    public function test_a_status_that_is_not_customer_visible_fires_nothing(): void
    {
        $listener = new ClaimStatusNotificationListener;
        $refund = $this->refundStub();

        $listener->handleStatusChange($refund, RefundStatusEnum::APPROVED->value, RefundStatusEnum::PROCESSING->value);

        $this->assertSame([], $this->fired, '환불 `processing`(PG 요청 중)은 고객에게 알릴 사건이 아닙니다.');
    }

    /**
     * @effects unchanged_status_recall_fires_nothing
     */
    public function test_a_recall_with_the_same_status_fires_nothing(): void
    {
        $listener = new ClaimStatusNotificationListener;
        $refund = $this->refundStub();

        $listener->handleStatusChange($refund, RefundStatusEnum::APPROVED->value, RefundStatusEnum::APPROVED->value);

        $this->assertSame([], $this->fired);
    }

    /**
     * @effects mapping_follows_captured_target_not_current_model_state
     */
    public function test_mapping_follows_the_captured_target_not_the_current_model_state(): void
    {
        // 큐가 밀린 사이 신청서가 또 전이된 상황: 모델은 이미 `collected` 인데
        // 이 잡이 나른 사건은 `approved` 다. 알림은 사건 기준으로 나가야 한다.
        $exchange = $this->requestedExchange();
        $exchange->forceFill(['exchange_status' => ExchangeStatusEnum::COLLECTED->value])->save();
        $this->fired = [];

        (new ClaimStatusNotificationListener)->handleStatusChange(
            $exchange->fresh(),
            ExchangeStatusEnum::REQUESTED->value,
            ExchangeStatusEnum::APPROVED->value,
        );

        $this->assertSame(['sirsoft-ecommerce.claim.exchange.notify_approved'], $this->fired);
    }

    /**
     * 전이 인자만 확인하면 되는 테스트용 환불신청서를 만듭니다.
     *
     * @return OrderRefund 환불신청서
     */
    private function refundStub(): OrderRefund
    {
        $refund = new OrderRefund;
        $refund->forceFill([
            'refund_number' => 'RF-TEST-1',
            'refund_status' => RefundStatusEnum::APPROVED->value,
        ]);

        return $refund;
    }
}
