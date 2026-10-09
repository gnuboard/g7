<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notifications;

use App\Extension\HookManager;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상태전이 → 알림 훅 매핑 테스트 (S7 / §4-8)
 *
 * 검증 축:
 * - 접수·전이가 **그 상태의 알림 훅 하나만** 발화한다(다른 28종은 조용해야 한다)
 * - 철회는 고객 알림이 아니라 관리자 수신 훅으로 간다 — 단, 운영자가 고객 요청으로 대신 철회하면 고객 훅도 함께 간다
 * - 알림 대상이 아닌 상태(환불 `processing`)와 미전이 재호출은 아무것도 발화하지 않는다
 * - 매핑 기준은 전이 시점 목표 상태다 — 모델의 "지금" 상태가 아니다(큐 지연 안전성)
 */
class ClaimStatusNotificationTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /** @var array<int, string> 이번 테스트에서 발화된 알림 훅 */
    private array $fired = [];

    /**
     * 통화·사유를 심고 알림 훅 발화를 모으는 리스너를 붙입니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테스트 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
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
        $hooks = [ClaimStatusNotificationListener::WITHDRAWN_HOOK, ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK];

        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach (array_keys($keys) as $status) {
                $hooks[] = ClaimStatusNotificationListener::notifyHookFor($claimType, $status);
            }
        }

        return $hooks;
    }

    /**
     * 반품을 접수합니다. (환불금 차감)
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 만든다)
     * @return OrderReturn 접수된 반품 신청서
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
     * 교환을 접수합니다. (승인 시점 결제)
     *
     * @return OrderExchange 접수된 교환 신청서
     */
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
     * 교환 접수가 접수 알림 훅 하나만 발화하는지 검증합니다.
     *
     * @effects request_fires_only_the_requested_notification_hook
     *
     * @return void 반환값 없음
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
     * 반품 승인이 매핑된 승인 알림 훅을 발화하는지 검증합니다.
     *
     * @effects transition_fires_only_the_mapped_notification_hook
     *
     * @return void 반환값 없음
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
     * 철회가 관리자 수신 훅만 발화하고 고객 훅은 내지 않는지 검증합니다.
     *
     * @effects withdrawal_fires_admin_hook_and_no_customer_hook
     *
     * @return void 반환값 없음
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
     * 운영자가 고객 요청으로 대신 철회하면 관리자 수신 훅과 함께 고객 수신 훅이 한 번 나가는지 검증합니다.
     * 고객이 한 철회가 아니므로 고객은 자기 신청이 사라진 이유를 이 알림으로만 안다.
     *
     * @effects withdrawal_on_behalf_fires_admin_and_customer_hooks
     *
     * @return void 반환값 없음
     */
    public function test_withdrawing_on_behalf_fires_the_admin_hook_and_the_customer_hook(): void
    {
        $return = $this->requestedReturn();
        $admin = $this->createUser();
        $this->fired = [];

        app(OrderReturnService::class)->withdraw($return, (int) $admin->id, '고객 전화 요청');

        $this->assertSame(
            [ClaimStatusNotificationListener::WITHDRAWN_HOOK, ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK],
            $this->fired,
            '대리 철회는 관리자 수신 알림과 고객 수신 알림을 한 번씩 발화해야 합니다.'
        );
    }

    /**
     * 고객에게 알리지 않는 상태(환불 처리중)는 아무 훅도 내지 않는지 검증합니다.
     *
     * @effects non_customer_visible_status_fires_nothing
     *
     * @return void 반환값 없음
     */
    public function test_a_status_that_is_not_customer_visible_fires_nothing(): void
    {
        $listener = new ClaimStatusNotificationListener;
        $refund = $this->refundStub();

        $listener->handleStatusChange($refund, RefundStatusEnum::APPROVED->value, RefundStatusEnum::PROCESSING->value);

        $this->assertSame([], $this->fired, '환불 `processing`(PG 요청 중)은 고객에게 알릴 사건이 아닙니다.');
    }

    /**
     * 같은 상태로의 재호출은 아무 훅도 내지 않는지 검증합니다.
     *
     * @effects unchanged_status_recall_fires_nothing
     *
     * @return void 반환값 없음
     */
    public function test_a_recall_with_the_same_status_fires_nothing(): void
    {
        $listener = new ClaimStatusNotificationListener;
        $refund = $this->refundStub();

        $listener->handleStatusChange($refund, RefundStatusEnum::APPROVED->value, RefundStatusEnum::APPROVED->value);

        $this->assertSame([], $this->fired);
    }

    /**
     * 알림 매핑이 모델의 지금 상태가 아니라 전이 시점 목표 상태를 따르는지 검증합니다.
     *
     * @effects mapping_follows_captured_target_not_current_model_state
     *
     * @return void 반환값 없음
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
     * 사건 알림 훅(송장 등록·부분 처리)을 받아 발화 순서와 스냅샷을 모읍니다.
     *
     * @return \ArrayObject<int, array{0: string, 1: array}> 발화된 훅과 스냅샷
     */
    private function captureEventHooks(): \ArrayObject
    {
        $captured = new \ArrayObject;

        foreach ([...array_keys(ClaimStatusNotificationListener::eventNotifyHooks()), ClaimNotificationHooks::PARTIALLY_PROCESSED] as $hook) {
            HookManager::addAction($hook, function ($claim, $snapshot = []) use ($captured, $hook) {
                $captured[] = [$hook, is_array($snapshot) ? $snapshot : []];
            }, 1);
        }

        return $captured;
    }

    /**
     * 「송장만 저장」 으로 운송장이 새로 적히면 송장 등록 알림 1통이 나가고 운송장 줄을 싣는다. 송장을 지우면 알릴 것이 없다.
     *
     * @effects tracking_registered_notification_fires_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_tracking_only_save_fires_one_tracking_registered_notification(): void
    {
        $return = app(OrderReturnService::class)->transition($this->requestedReturn(), ReturnStatusEnum::APPROVED);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;
        $captured = $this->captureEventHooks();
        $this->fired = [];

        app(OrderReturnService::class)->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $this->testClaimCarrier()->id, 'NOTI-1');

        $this->assertCount(1, $captured);
        $this->assertSame('sirsoft-ecommerce.claim.return.notify_collection_tracking_registered', $captured[0][0]);
        $this->assertStringContainsString('NOTI-1', $captured[0][1]['tracking_lines']);
        $this->assertSame([], $this->fired, '상태가 그대로인데 상태 알림이 나갔습니다.');

        app(OrderReturnService::class)->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], null, null);
        $this->assertCount(1, $captured, '송장을 지웠는데 송장 등록 알림이 나갔습니다.');
    }

    /**
     * 알림의 운송장 줄은 「택배사 · 운송장 · 상품명 × 수량」 이다 — 상품명이 빠지면 고객은 어느 상자에 무엇이
     * 담겼는지 모른다. 알림 경로는 신청서를 관계 없이 다시 읽으므로 상품 행의 주문옵션을 스스로 불러야 한다
     * (2026-10-07 실측: 메일 「CJ대한통운 · S1C1RTNA02 ·  × 2」 — 상품명 자리가 비었다).
     *
     * @effects tracking_lines_carry_product_names
     *
     * @return void 반환값 없음
     */
    public function test_tracking_lines_carry_product_names(): void
    {
        $return = app(OrderReturnService::class)->transition($this->requestedReturn(), ReturnStatusEnum::APPROVED);
        $item = $return->fresh('returnOptions.orderOption')->returnOptions->first();
        $raw = $item->orderOption->product_name;
        $expected = is_array($raw) ? ($raw[app()->getLocale()] ?? reset($raw)) : (string) $raw;
        $captured = $this->captureEventHooks();

        app(OrderReturnService::class)->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [(int) $item->id], $this->testClaimCarrier()->id, 'NOTI-NAME');

        $line = (string) ($captured[0][1]['tracking_lines'] ?? '');
        $this->assertNotSame('', $expected);
        $this->assertStringContainsString('NOTI-NAME', $line);
        $this->assertStringContainsString($expected, $line, '운송장 줄에 상품명이 없습니다: '.$line);
        $this->assertStringNotContainsString(' ·  × ', $line);
    }

    /**
     * 같은 요청에서 신청서가 회수중으로 옮겨지면 상태 알림(회수중)이 운송장 줄을 싣고 나가고, 송장 등록 알림은 억제된다.
     *
     * @effects tracking_registered_notification_is_suppressed_by_the_status_notification
     *
     * @return void 반환값 없음
     */
    public function test_collecting_transition_suppresses_the_tracking_registered_notification(): void
    {
        $return = app(OrderReturnService::class)->transition($this->requestedReturn(), ReturnStatusEnum::APPROVED);
        $captured = $this->captureEventHooks();
        $snapshots = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.notify_collecting', function ($claim, $snapshot = []) use (&$snapshots) {
            $snapshots[] = $snapshot;
        }, 1);
        $this->fired = [];

        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::COLLECTING, [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'NOTI-MOVE',
        ]);

        $this->assertSame(['sirsoft-ecommerce.claim.return.notify_collecting'], $this->fired);
        $this->assertCount(0, $captured, '상태 알림과 송장 등록 알림이 함께 나갔습니다.');
        $this->assertStringContainsString('NOTI-MOVE', (string) ($snapshots[0]['tracking_lines'] ?? ''));
    }

    /**
     * 부분 처리 알림은 심사 결정에만 나가고, 「검토 중」 은 아직 심사 단계인 수량만 센다. 물류 단계(회수도착)는 부분 처리
     * 알림을 내지 않는다. (S11)
     *
     * @effects partial_processing_notification_is_limited_to_review_decisions
     *
     * @return void 반환값 없음
     */
    public function test_partial_processing_is_announced_only_for_review_decisions(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 2]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;
        $captured = $this->captureEventHooks();

        app(AdminClaimService::class)->transitionOptions($return->fresh(['returnOptions']), [['id' => $itemId, 'quantity' => 1]], 'approved');

        $partial = array_values(array_filter((array) $captured->getArrayCopy(), fn ($row) => $row[0] === ClaimNotificationHooks::PARTIALLY_PROCESSED));
        $this->assertCount(1, $partial);
        $this->assertSame('1', $partial[0][1]['processed_count']);
        $this->assertSame('1', $partial[0][1]['remaining_count']);

        app(AdminClaimService::class)->transitionOptions($return->fresh(['returnOptions']), [$itemId], 'collected');

        $partial = array_values(array_filter((array) $captured->getArrayCopy(), fn ($row) => $row[0] === ClaimNotificationHooks::PARTIALLY_PROCESSED));
        $this->assertCount(1, $partial, '물류 단계 전이가 부분 처리 알림을 냈습니다.');
    }

    /**
     * 선교환의 발송중 알림은 발행 시점의 운송장 줄과 반송 기한 안내(`advance_exchange_notice`)를 스냅샷으로 싣는다.
     *
     * @effects advance_redelivering_notification_carries_the_due_notice
     *
     * @return void 반환값 없음
     */
    public function test_advance_redelivering_notification_carries_the_due_notice(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $service = app(OrderExchangeService::class);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'requested_by' => $order->user_id,
        ]);
        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);

        $snapshots = [];
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.notify_redelivering', function ($claim, $snapshot = []) use (&$snapshots) {
            $snapshots[] = $snapshot;
        }, 1);

        // 서울 기준으로는 이미 다음 날인 UTC 저녁 — 안내 날짜가 UTC 날짜로 새면 하루 어긋난다(Chrome 실측 A-4).
        app()->instance('user_timezone', 'Asia/Seoul');
        Carbon::setTestNow(Carbon::parse('2026-09-24 20:00:00', 'UTC'));

        $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'ADV-NOTI',
        ]);

        $this->assertCount(1, $snapshots);
        $this->assertStringContainsString('ADV-NOTI', (string) ($snapshots[0]['tracking_lines'] ?? ''));
        $this->assertNotSame('', (string) ($snapshots[0]['advance_exchange_notice'] ?? ''));
        // 기한은 발송 시각 + 14일(UTC 2026-10-08 20:00) — 서울 날짜 2026-10-09 로 안내한다.
        $this->assertStringContainsString('2026-10-09', (string) ($snapshots[0]['advance_exchange_notice'] ?? ''));
        Carbon::setTestNow();
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
