<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Listeners\NotificationHookListener;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 뒤늦은 처리 API 테스트 (#599 후속 §5 S13~S15 — 「그 순간에만 열리는 입구」·「고객만 할 수 있는 처리」)
 *
 * 검증 축:
 * - S13 뒤늦은 재고 복구 — 재고가 실제로 늘고 행에 기록 · 두 번째 요청 422(멱등) · 선교환 미회수 행 제외 · 권한 403
 * - S14 고객 요청 대리 철회 — 철회 가능 단계만 · 사유 필수 · 결제된 청구 환급 · 철회자·사유 기록 · 알림 문구 · 권한 403
 *   · 고객에게 대리 철회 알림 한 통 · 고객 스스로 철회면 고객 알림 없음
 * - S15 신청 내용 수정 — 판매자 귀책(고객 비노출) 사유 허용 · 사유 변경이 배송비를 다시 계산 · 승인 뒤 거절 · 권한 403
 *   · 활동 로그에 사유·귀책·배송비 전/후와 운영자 대리 수정 사실이 남음 · 바뀐 것이 없으면 기록 없음
 */
class ClaimAdminActionsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 클레임 조회·상태 변경 권한 운영자와 판매자 귀책(고객 비노출) 사유를 세우고 PG 환불 훅을 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        foreach (['exchange', 'return'] as $type) {
            ClaimReason::firstOrCreate(
                ['type' => $type, 'code' => 'seller_wrong_shipment'],
                [
                    'name' => ['ko' => '오배송', 'en' => 'Wrong shipment'],
                    'fault_type' => ClaimReasonFaultTypeEnum::SELLER,
                    'is_user_selectable' => false,
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );
        }

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
        ]);
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 API 의 공통 접두 경로를 돌려줍니다.
     *
     * @return string 관리자 라우트 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 클레임 조회 권한만 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    protected function readOnlyAdmin(): User
    {
        return $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
    }

    /**
     * 반품을 접수합니다. (환불금 차감 — 고객 귀책 회수비 3,000원)
     *
     * @param  Order|null  $order  주문 (없으면 배송완료 주문을 만든다)
     * @return OrderReturn 반품 신청서
     */
    protected function requestedReturn(?Order $order = null): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ])->fresh(['returnOptions']);
    }

    /**
     * 반품을 재고 복구 없이 완료합니다.
     *
     * @return OrderReturn 완료된 반품
     */
    protected function completedReturnWithoutRestock(): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($this->requestedReturn(), ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => ['restore_stock_by_item' => [$itemId => false], 'create_refund_request' => false],
        ])->assertOk();

        return $return->fresh(['returnOptions']);
    }

    /**
     * 주문 상품 행이 가리키는 상품 옵션의 재고를 돌려줍니다.
     *
     * @param  int  $orderOptionId  주문 상품 행 ID
     * @return int 재고
     */
    protected function stockOf(int $orderOptionId): int
    {
        return (int) ProductOption::find(OrderOption::find($orderOptionId)->product_option_id)->stock_quantity;
    }

    // ================================================================
    // S13 — 뒤늦은 재고 복구
    // ================================================================

    /**
     * 완료 때 재고를 복구하지 않은 행은 뒤늦게 복구할 수 있다 — 재고가 실제로 늘고 행에 기록되며, 대상을 비우면
     * 복구 가능한 행 전부다. 두 번째 요청은 복구할 행이 없어 422 이고 재고는 더 늘지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_restore_stock_increments_stock_once
     */
    public function test_restore_stock_later_increments_the_stock_once(): void
    {
        $return = $this->completedReturnWithoutRestock();
        $item = $return->returnOptions->first();
        $before = $this->stockOf((int) $item->order_option_id);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/items/restore-stock", [])
            ->assertOk()
            ->assertJsonPath('data.restored.0.id', $item->id)
            ->assertJsonPath('data.restored.0.quantity', 1);

        $this->assertSame($before + 1, $this->stockOf((int) $item->order_option_id));
        $this->assertTrue((bool) OrderReturnOption::find($item->id)->is_stock_restored);
        $this->assertTrue((bool) $return->fresh()->is_stock_restored);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/items/restore-stock", ['items' => [$item->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_stock_restore_not_eligible'));

        $this->assertSame($before + 1, $this->stockOf((int) $item->order_option_id), '재고가 두 번 복구됐습니다.');
    }

    /**
     * 선교환에서 미회수로 완료한 행은 헌 물건이 오지 않았으므로 재고 복구 대상이 아니다. (D3)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_restore_stock_skips_unreturned_rows
     */
    public function test_restore_stock_skips_unreturned_rows(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $service = app(OrderExchangeService::class);
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);
        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);
        $exchange = $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERING));
        $itemId = (int) $exchange->fresh('exchangeOptions')->exchangeOptions->first()->id;
        $service->completeExchange($exchange->fresh(), [$itemId], new ClaimCompletionOptions([], false, [$itemId => '반송 안 됨']));
        $before = $this->stockOf((int) $order->options->first()->id);

        $this->actingAs($this->admin)->postJson($this->base()."/exchanges/{$exchange->id}/items/restore-stock", [])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_stock_restore_not_eligible'));

        $this->assertSame($before, $this->stockOf((int) $order->options->first()->id));
        $this->assertFalse((bool) OrderExchangeOption::find($itemId)->is_stock_restored);
    }

    /**
     * 상태 변경 권한이 없으면 재고 복구·대리 철회·신청 내용 수정 모두 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_after_actions_require_update_status_permission
     */
    public function test_after_actions_require_the_update_status_permission(): void
    {
        $completed = $this->completedReturnWithoutRestock();
        $requested = $this->requestedReturn();
        $readOnly = $this->readOnlyAdmin();

        $this->actingAs($readOnly)->postJson($this->base()."/returns/{$completed->id}/items/restore-stock", [])->assertForbidden();
        $this->actingAs($readOnly)->postJson($this->base()."/returns/{$requested->id}/withdraw", ['reason' => '고객 요청'])->assertForbidden();
        $this->actingAs($readOnly)->patchJson($this->base()."/returns/{$requested->id}", ['reason_detail' => '수정'])->assertForbidden();

        $this->assertFalse((bool) $completed->returnOptions->first()->fresh()->is_stock_restored);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $requested->fresh()->return_status);
    }

    // ================================================================
    // S14 — 고객 요청 대리 철회
    // ================================================================

    /**
     * 대리 철회는 사유가 필수이고, 결제된 청구를 환급하며 철회자·사유를 남긴다. 알림은 판매자 대리 철회 문구를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_withdraw_on_behalf_refunds_the_paid_charge_and_records_the_actor
     */
    public function test_withdraw_on_behalf_refunds_the_paid_charge_and_records_the_actor(): void
    {
        $customer = $this->createUser();
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $customer->id])->save();
        $order = $order->fresh(['options', 'shippingAddress']);
        $this->grantMileage($customer->id, 10000);

        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
            'requested_by' => $customer->id,
        ], [], 'point');
        $return = $result['claim'];
        $this->assertSame('paid', $result['payment']->payment_status->value);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/withdraw", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/withdraw", ['reason' => '고객 전화 요청'])
            ->assertOk()
            ->assertJsonPath('data.claim.status', 'withdrawn');

        $fresh = $return->fresh();
        $this->assertSame(ReturnStatusEnum::WITHDRAWN, $fresh->return_status);
        $this->assertSame((int) $this->admin->id, (int) $fresh->withdrawn_by_admin);
        $this->assertSame('고객 전화 요청', $fresh->withdraw_reason);
        $this->assertSame('refunded', $result['payment']->fresh()->payment_status->value, '대리 철회가 결제된 청구를 환급하지 않았습니다.');

        $data = app(ClaimNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'claim_withdrawn',
            [$fresh],
        )['data'];
        $this->assertSame(__('sirsoft-ecommerce::messages.claims.notifications.withdraw_origin_admin'), $data['withdraw_origin_notice']);
    }

    /**
     * 대리 철회도 고객 철회와 같은 단계에서만 된다 — 승인 뒤에는 422 이고 상태는 그대로다. 고객 본인 철회의
     * 알림 문구는 비어 있다(대조군).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_withdraw_on_behalf_follows_the_customer_withdraw_gate
     */
    public function test_withdraw_on_behalf_follows_the_customer_withdraw_gate(): void
    {
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $service = app(OrderExchangeService::class);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);
        $approved = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);

        $this->actingAs($this->admin)->postJson($this->base()."/exchanges/{$approved->id}/withdraw", ['reason' => '고객 요청'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_not_withdrawable'));
        $this->assertSame(ExchangeStatusEnum::APPROVED, $approved->fresh()->exchange_status);

        $selfWithdrawn = $this->requestedReturn();
        app(OrderReturnService::class)->withdraw($selfWithdrawn);
        $data = app(ClaimNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'claim_withdrawn',
            [$selfWithdrawn->fresh()],
        )['data'];
        $this->assertSame('', $data['withdraw_origin_notice']);
    }

    /**
     * 운영자가 고객 요청으로 대신 철회하면 고객에게 「고객님 요청으로 판매자가 철회했습니다」 알림이 한 통 간다 —
     * 확인 창이 고객에게 안내된다고 약속하는데, 철회 알림이 관리자 수신 한 종뿐이면 고객은 자기 신청이 왜
     * 사라졌는지 알 길이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_withdraw_on_behalf_notifies_the_customer
     */
    public function test_withdraw_on_behalf_sends_the_customer_one_notification(): void
    {
        [$customer, $return] = $this->memberReturn();
        $this->subscribeWithdrawnOnBehalfNotification();
        Notification::fake();

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/withdraw", ['reason' => '고객 전화 요청'])
            ->assertOk();

        $sent = $this->withdrawnOnBehalfSentTo($customer);
        $this->assertCount(1, $sent, '대리 철회인데 고객 알림(알림센터)이 한 통이 아닙니다.');

        // 본문은 수신자 언어로 렌더된다 — 한국어 고객으로 고정해 약속한 문구를 그대로 확인한다.
        $customer->language = 'ko';
        $body = $sent->first()->toArray($customer)['body'];
        $this->assertStringContainsString('고객님 요청으로 판매자가 철회했습니다', $body);
        $this->assertStringContainsString((string) $return->return_number, $body);
    }

    /**
     * 고객이 스스로 철회하면 고객 알림은 나가지 않는다 — 자기가 한 철회를 자기에게 되돌려 보낼 이유가 없다(대조군).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects customer_self_withdraw_sends_no_customer_withdraw_notification
     */
    public function test_self_withdraw_sends_the_customer_no_notification(): void
    {
        [$customer, $return] = $this->memberReturn();
        $this->subscribeWithdrawnOnBehalfNotification();
        Notification::fake();

        app(OrderReturnService::class)->withdraw($return);

        $this->assertSame(ReturnStatusEnum::WITHDRAWN, $return->fresh()->return_status);
        $this->assertCount(0, $this->withdrawnOnBehalfSentTo($customer));
    }

    /**
     * 회원 주문의 반품을 고객 본인 이름으로 접수합니다.
     *
     * @return array{0: User, 1: OrderReturn} [고객, 반품 신청서]
     */
    protected function memberReturn(): array
    {
        $customer = $this->createUser();
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $customer->id])->save();

        return [$customer, $this->requestedReturn($order->fresh(['options', 'shippingAddress']))];
    }

    /**
     * 대리 철회 고객 알림 정의를 DB 에 시드하고 그 훅을 **한 번만** 구독합니다.
     *
     * 선언형 정의는 `module:update` 가 동기화하는데 테스트 DB 에는 그 경로가 돌지 않는다 — 같은 helper 로
     * 시드해 「선언 → DB → 훅 구독 → 발송」 전 구간을 밟는다. 앞선 테스트가 정의를 남긴 DB 로 부팅했으면
     * 부팅 때 이미 구독돼 있을 수 있어, 비우고 다시 걸어야 한 사건에 한 통이 세어진다.
     *
     * @return void 반환값 없음
     */
    protected function subscribeWithdrawnOnBehalfNotification(): void
    {
        $declared = collect(app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getNotificationDefinitions())
            ->firstWhere('type', 'claim_withdrawn_on_behalf');
        $this->assertNotNull($declared, '대리 철회 고객 알림 정의(claim_withdrawn_on_behalf)가 선언되지 않았습니다.');

        $helper = app(NotificationSyncHelper::class);
        $definition = $helper->syncDefinition($declared + ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce']);

        foreach ($declared['templates'] as $template) {
            $helper->syncTemplate($definition->id, $template);
        }

        app(NotificationDefinitionService::class)->invalidateAllCache();

        HookManager::clearAction(ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK);
        HookListenerRegistrar::registerDynamicAction(
            hookName: ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK,
            listenerClass: NotificationHookListener::class,
            method: 'dispatchForDefinition',
            boundArgs: [$definition->fresh()],
            priority: 30,
        );
    }

    /**
     * 고객에게 알림센터 채널로 간 대리 철회 알림을 돌려줍니다.
     *
     * @param  User  $customer  고객
     * @return Collection<int, GenericNotification> 보낸 알림
     */
    protected function withdrawnOnBehalfSentTo(User $customer): Collection
    {
        return Notification::sent(
            $customer,
            GenericNotification::class,
            fn (GenericNotification $notification, array $channels) => $notification->getType() === 'claim_withdrawn_on_behalf'
                && in_array('database', $channels, true),
        );
    }

    /**
     * 관리자 상세는 판매자 접수를 한 운영자와 대신 철회한 운영자의 이름을 싣는다 — 누가 고객 대신 접수·철회했는지
     * 상세에서 바로 보여야 운영자끼리 되묻지 않는다. 고객 화면 응답에는 운영자 이름이 실리지 않고(내부 인력 정보),
     * 고객이 낸 신청의 관리자 상세는 접수 운영자가 비어 있다(대조군).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_detail_names_the_filing_and_withdrawing_operator
     */
    public function test_admin_detail_names_the_filing_and_withdrawing_operator(): void
    {
        $filer = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
        $filer->forceFill(['name' => '접수 운영자'])->save();
        $this->admin->forceFill(['name' => '철회 운영자'])->save();

        $customer = $this->createUser();
        $order = $this->deliveredClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => $customer->id])->save();
        $order = $order->fresh(['options', 'shippingAddress']);

        $adminReturn = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'operator' => true,
            'requested_by_admin' => $filer->id,
        ]);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$adminReturn->id}/withdraw", ['reason' => '고객 전화 요청'])
            ->assertOk();

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$adminReturn->id}")
            ->assertOk()
            ->assertJsonPath('data.claim.requested_by_admin_name', '접수 운영자')
            ->assertJsonPath('data.claim.withdrawn_by_admin_name', '철회 운영자');

        $this->actingAs($customer)->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$adminReturn->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.is_admin_requested', true)
            ->assertJsonMissingPath('data.claim.requested_by_admin_name')
            ->assertJsonMissingPath('data.claim.withdrawn_by_admin_name');

        $customerReturn = $this->requestedReturn($order->fresh(['options', 'shippingAddress']));

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$customerReturn->id}")
            ->assertOk()
            ->assertJsonPath('data.claim.requested_by_admin_name', null)
            ->assertJsonPath('data.claim.withdrawn_by_admin_name', null);
    }

    // ================================================================
    // S15 — 신청 내용 수정
    // ================================================================

    /**
     * 운영자는 사유를 판매자 귀책(고객 비노출) 사유로 고칠 수 있고, 귀책이 바뀌면 회수비가 다시 계산된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_update_request_recalculates_the_fee_on_reason_change
     */
    public function test_update_request_to_a_seller_fault_reason_recalculates_the_fee(): void
    {
        $return = $this->requestedReturn();
        $this->assertEquals(3000, (float) $return->claim_shipping_fee);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}", [
            'reason_code' => 'seller_wrong_shipment',
            'reason_detail' => '다른 색상 발송',
        ])->assertOk()
            ->assertJsonPath('data.claim.reason_code', 'seller_wrong_shipment');

        $fresh = $return->fresh();
        $this->assertSame('seller_wrong_shipment', $fresh->reason_code);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $fresh->fault_type);
        $this->assertSame('다른 색상 발송', $fresh->reason_detail);
        $this->assertEquals(0, (float) $fresh->claim_shipping_fee, '판매자 귀책으로 바꿨는데 회수비가 남았습니다.');
        $this->assertEquals(0, (float) $fresh->total_charge_amount);
        $this->assertNull($fresh->charge_method);
    }

    /**
     * 신청 내용 수정은 고객 수정과 같은 단계에서만 된다 — 승인 뒤에는 422 이고 사유는 그대로다. 없는 사유 코드도 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_update_request_is_limited_to_editable_stages
     */
    public function test_update_request_is_rejected_after_approval(): void
    {
        $requested = $this->requestedReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$requested->id}", ['reason_code' => 'no_such_reason'])
            ->assertStatus(422);
        $this->assertSame('changed_mind', $requested->fresh()->reason_code);

        $approved = app(OrderReturnService::class)->transition($requested, ReturnStatusEnum::APPROVED);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$approved->id}", ['reason_code' => 'seller_wrong_shipment'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_not_editable'));

        $this->assertSame('changed_mind', $approved->fresh()->reason_code);
    }

    /**
     * 운영자가 고객 대신 사유를 고치면 활동 로그 한 줄이 남는다 — 사유(라벨)·귀책·회수비의 전/후와 「운영자 대리 수정」
     * 사실, 작업자가 함께 실린다. 누가 배송비를 내는지가 바뀌는 수정이라 흔적 없이 바뀌면 분쟁 때 근거가 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_update_request_logs_before_and_after_with_the_operator_flag
     */
    public function test_update_request_on_behalf_logs_the_before_and_after_reason_and_fee(): void
    {
        $return = $this->requestedReturn();
        ActivityLog::query()->delete();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}", [
            'reason_code' => 'seller_wrong_shipment',
        ])->assertOk();

        $logs = ActivityLog::where('action', 'claim_return.request_update')->get();
        $this->assertCount(1, $logs, '신청 내용 수정이 활동 로그에 남지 않았습니다.');

        $log = $logs->first();
        $this->assertSame('sirsoft-ecommerce::activity_log.description.claim_return_request_update_on_behalf', $log->description_key);
        $this->assertSame($return->return_number, $log->description_params['claim_number']);
        $this->assertTrue($log->properties['operator']);
        $this->assertSame($this->admin->id, $log->properties['processed_by']);
        $this->assertSame($return->id, $log->loggable_id);

        $changed = collect($log->changes)->keyBy('field');
        // 사유는 표시 시점 로케일로 읽히도록 다국어 이름 원본을 싣는다(라벨 박제 금지).
        $this->assertSame(['ko' => 'changed_mind', 'en' => 'changed_mind'], $changed['reason']['old']);
        $this->assertSame(['ko' => '오배송', 'en' => 'Wrong shipment'], $changed['reason']['new']);
        $this->assertSame('json', $changed['reason']['type']);
        $this->assertSame(ClaimReasonFaultTypeEnum::CUSTOMER->value, $changed['fault_type']['old']);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER->value, $changed['fault_type']['new']);
        $this->assertSame('sirsoft-ecommerce::enums.claim_reason_fault_type.seller', $changed['fault_type']['new_label_key']);
        // JSON 왕복에서 소수점 없는 실수는 정수로 돌아온다 — 값의 동일성만 본다.
        $this->assertEquals(3000, $changed['claim_shipping_fee']['old']);
        $this->assertEquals(0, $changed['claim_shipping_fee']['new']);
        $this->assertSame('currency', $changed['claim_shipping_fee']['type']);
    }

    /**
     * 같은 사유·같은 상세 사유로 다시 저장하면(바뀐 것이 없으면) 활동 로그를 남기지 않는다 — 저장할 때마다 빈 이력이
     * 쌓이면 실제로 바뀐 줄이 묻힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_update_request_without_a_change_logs_nothing
     */
    public function test_update_request_on_behalf_without_a_change_logs_nothing(): void
    {
        $return = $this->requestedReturn();
        $payload = ['reason_code' => 'changed_mind', 'reason_detail' => '색상이 사진과 다름'];

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}", $payload)->assertOk();
        ActivityLog::query()->delete();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}", $payload)->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'claim_return.request_update')->count());
    }

    // ================================================================
    // F1 — 반품 완료 중 쿠폰 복원 실패는 완료 전체를 되돌린다
    // ================================================================

    /**
     * 전건 완료 반품의 쿠폰 복원이 실패하면 완료가 되돌려지고 422(`side_effect_failed`)로 알린다 —
     * 예전에는 기록만 하고 완료로 확정해, 환불은 끝났는데 쿠폰이 사용됨으로 남았다(F1·D2).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects return_completion_rolls_back_when_coupon_restore_fails
     */
    public function test_return_completion_rolls_back_when_coupon_restore_fails(): void
    {
        // 재계산이 적용하지 못하는 쿠폰이 원 주문에 있었다 = 조건 소멸 → 완료 시 복원 대상.
        $order = $this->deliveredClaimOrder(orderOverrides: [
            'promotions_applied_snapshot' => ['coupon_issue_ids' => [987654]],
        ]);
        $service = app(OrderReturnService::class);
        $return = $service->transition($this->requestedReturn($order), ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;

        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function () {
            throw new \RuntimeException('쿠폰 잠금 실패');
        }, 5);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => ['restore_stock_by_item' => [$itemId => false], 'create_refund_request' => false],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::messages.claims.side_effect_failed'));

        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->fresh()->return_status);
    }
}
