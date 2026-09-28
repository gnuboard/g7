<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 판매자 접수 신청의 고객 쪽 계약 (#599 후속 §3-11 · D8)
 *
 * 판매자가 고객을 대신해 접수한 교환·반품은 고객(회원·비회원)이 철회·수정할 수 없다. 화면은 같은 판정으로
 * 버튼을 숨기고 「판매자 접수」 를 표시하며, 접수 알림은 판매자 접수 사실을 덧붙인다.
 *
 * 검증 축:
 * - 회원 창구·비회원 창구의 철회·수정이 `claim_admin_requested_not_editable` 422 로 거절되고 아무것도 바뀌지 않는다
 * - `ClaimResource` 출처 `admin` · `is_withdrawable`/`is_editable` false (대조군: 같은 주문의 고객 신청은 true)
 * - `requested` 알림 변수 `request_origin_notice` — 판매자 접수면 안내 문구, 고객 신청이면 빈 문자열
 */
class AdminRequestedClaimCustomerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const GUEST_PASSWORD = 'guest12';

    private const GUEST_PHONE = '010-2345-6789';

    protected User $customer;

    protected User $admin;

    /**
     * 통화·사유를 심고 회원·운영자를 만든 뒤 PG 환불 훅과 첨부 저장소를 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->customer = $this->createUser();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.create',
            'sirsoft-ecommerce.claims.update-status',
        ]);

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $storage->allows('response')->andReturn(null)->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 통화 설정과 목(mock)을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 이 회원의 배송완료 주문을 만듭니다. (수량 2 — 판매자 접수와 고객 신청을 한 주문에 나란히 둔다)
     *
     * @return Order 배송완료 주문
     */
    protected function memberOrder(): Order
    {
        $order = $this->deliveredClaimOrder(quantity: 2, exchangeFee: 0);
        $order->forceFill(['user_id' => $this->customer->id])->save();

        return $order->fresh(['options', 'shippingAddress']);
    }

    /**
     * 배송완료 비회원 주문과 조회 토큰을 만듭니다.
     *
     * @return array{0: Order, 1: string} [주문, 토큰]
     */
    protected function guestOrderWithToken(): array
    {
        $order = $this->deliveredClaimOrder(quantity: 2, exchangeFee: 0);
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::GUEST_PHONE]);

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::GUEST_PHONE, self::GUEST_PASSWORD, '10.0.0.9')['token'];

        return [$order->fresh(['options', 'shippingAddress']), $token];
    }

    /**
     * 판매자 접수 반품을 만듭니다. (운영자 축 · 신청 상태 유지)
     *
     * @param  Order  $order  주문
     * @return OrderReturn 반품 신청서
     */
    protected function adminReturn(Order $order): OrderReturn
    {
        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'operator' => true,
            'requested_by_admin' => $this->admin->id,
        ])->fresh();
    }

    /**
     * 판매자 접수 교환을 만듭니다. (왕복 배송비 0원 · 신청 상태 유지)
     *
     * @param  Order  $order  주문
     * @return OrderExchange 교환 신청서
     */
    protected function adminExchange(Order $order): OrderExchange
    {
        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'operator' => true,
            'requested_by_admin' => $this->admin->id,
        ])->fresh();
    }

    /**
     * 판매자 접수 거절 문구를 로케일별로 돌려줍니다. (응답 로케일은 요청자 설정을 따른다)
     *
     * @return array<int, string> ko·en 문구
     */
    protected function notEditableMessages(): array
    {
        return [
            __('sirsoft-ecommerce::exceptions.claim_admin_requested_not_editable', [], 'ko'),
            __('sirsoft-ecommerce::exceptions.claim_admin_requested_not_editable', [], 'en'),
        ];
    }

    /**
     * 회원 창구로 판매자 접수 교환·반품을 철회·수정하면 거절되고 신청서는 그대로다. (D8)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_requested_claim_rejects_member_withdraw_and_update
     */
    public function test_member_cannot_withdraw_or_update_an_admin_requested_claim(): void
    {
        $order = $this->memberOrder();
        $return = $this->adminReturn($order);
        $exchange = $this->adminExchange($order);
        $base = '/api/modules/sirsoft-ecommerce/user/claims';
        $messages = $this->notEditableMessages();

        foreach ([$return->return_number, $exchange->exchange_number] as $number) {
            $this->actingAs($this->customer)->postJson("{$base}/{$number}/withdraw")
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message) => in_array($message, $messages, true));

            $this->actingAs($this->customer)->patchJson("{$base}/{$number}", ['reason_detail' => '고객이 고친 사유'])
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message) => in_array($message, $messages, true));
        }

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertNotSame('고객이 고친 사유', $return->fresh()->reason_detail);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);
        $this->assertNotSame('고객이 고친 사유', $exchange->fresh()->reason_detail);
    }

    /**
     * 비회원 창구(토큰)도 같은 이유로 거절한다. (D8)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_requested_claim_rejects_guest_withdraw_and_update
     */
    public function test_guest_cannot_withdraw_or_update_an_admin_requested_claim(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        $return = $this->adminReturn($order);
        $exchange = $this->adminExchange($order);
        $base = "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/claims";
        $headers = ['X-Guest-Order-Token' => $token];
        $messages = $this->notEditableMessages();

        foreach ([$return->return_number, $exchange->exchange_number] as $number) {
            $this->postJson("{$base}/{$number}/withdraw", [], $headers)
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message) => in_array($message, $messages, true));

            $this->patchJson("{$base}/{$number}", ['reason_detail' => '비회원이 고친 사유'], $headers)
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message) => in_array($message, $messages, true));
        }

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);
    }

    /**
     * 신청서 리소스는 판매자 접수를 출처 `admin` 으로 싣고 구매자 철회·수정 게이트를 닫는다. 같은 주문의 고객
     * 신청은 출처 `customer` 이고 게이트가 열린다(대조군). 관리자 상세의 대리 철회 게이트는 판매자 접수에도 열린다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_requested_claim_resource_reports_origin_and_closes_buyer_gates
     */
    public function test_resource_reports_the_admin_origin_and_closes_buyer_gates(): void
    {
        $order = $this->memberOrder();
        $adminReturn = $this->adminReturn($order);
        $customerReturn = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $this->customer->id,
        ]);

        $this->actingAs($this->customer)->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$adminReturn->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.request_source', 'admin')
            ->assertJsonPath('data.claim.is_admin_requested', true)
            ->assertJsonPath('data.claim.is_withdrawable', false)
            ->assertJsonPath('data.claim.is_editable', false);

        $this->actingAs($this->customer)->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$customerReturn->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.request_source', 'customer')
            ->assertJsonPath('data.claim.is_admin_requested', false)
            ->assertJsonPath('data.claim.is_withdrawable', true)
            ->assertJsonPath('data.claim.is_editable', true);

        $this->actingAs($this->admin)->getJson("/api/modules/sirsoft-ecommerce/admin/returns/{$adminReturn->id}")
            ->assertOk()
            ->assertJsonPath('data.claim.request_source', 'admin')
            ->assertJsonPath('data.on_behalf.can_withdraw', true)
            ->assertJsonPath('data.on_behalf.can_edit', true);
    }

    /**
     * 접수 알림 변수 `request_origin_notice` 는 판매자 접수면 안내 문구, 고객 신청이면 빈 문자열이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_requested_claim_notification_carries_the_origin_notice
     */
    public function test_requested_notification_carries_the_origin_notice(): void
    {
        $order = $this->memberOrder();
        $listener = app(ClaimNotificationDataListener::class);
        $empty = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $adminExchange = $this->adminExchange($order);
        $customerExchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'requested_by' => $this->customer->id,
        ]);

        $adminData = $listener->extractData($empty, 'exchange_requested', [$adminExchange])['data'];
        $customerData = $listener->extractData($empty, 'exchange_requested', [$customerExchange])['data'];

        $this->assertSame(__('sirsoft-ecommerce::messages.claims.notifications.request_origin_admin'), $adminData['request_origin_notice']);
        $this->assertNotSame('', $adminData['request_origin_notice']);
        $this->assertSame('', $customerData['request_origin_notice']);

        // 판매자 접수 알림도 주문 주인(회원)에게 간다.
        $this->assertSame($this->customer->id, $listener->extractData($empty, 'exchange_requested', [$adminExchange])['context']['trigger_user_id'] ?? null);
    }
}
