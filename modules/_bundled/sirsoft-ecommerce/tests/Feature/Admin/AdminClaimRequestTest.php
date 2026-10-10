<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 직권 접수 Feature 테스트 (§3-11 · D5~D8 · S13~S15)
 *
 * 검증 축:
 * - 물리 게이트(D5) — 신청 기간이 지난 배송완료 주문도 교환·반품을 받고, 아직 보내지 않은 상품은
 *   교환·반품을 거절한다. 취소·환불은 그 반대다.
 * - 운영자 축 — 판매자 귀책(고객 비노출) 사유, 배송비 직접 입력·「무료」(D7), 「접수와 함께 승인」(D6)
 * - 판매자 접수(D8) — `requested_by_admin` 기록, 고객 철회·수정 차단
 * - 권한 — `claims.create` 없으면 403
 * - 대리 철회(S14) · 신청 내용 수정(S15) · 뒤늦은 재고 복구(S13) · 목록 접수 출처 필터
 * - 운영자 축이 풀지 않는 것 — 수량 잠금, 다른 주문의 상품 거절
 * - 승인 분기(D6) — 승인이 막히면 접수는 남고 `approval_skipped`, 미수납 청구는 승인 대신 결제대기
 */
class AdminClaimRequestTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 직권 접수·상태 전이 권한을 가진 운영자와 사유 카탈로그(판매자 귀책 비노출 사유 포함)를 세웁니다.
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

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.create',
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
     * 접수 응답이 201 인지 확인합니다. (실패하면 응답 본문을 메시지로 남긴다)
     *
     * @param  TestResponse  $response  접수 응답
     * @return void 반환값 없음
     */
    protected function assertFiled(TestResponse $response): void
    {
        $this->assertSame(201, $response->status(), (string) $response->getContent());
    }

    /**
     * 신청 기간(7일)이 지난 배송완료 주문을 만듭니다. (배송완료 10일 경과)
     *
     * @return Order 배송완료 주문
     */
    protected function staleDeliveredOrder(): Order
    {
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 7);

        $order = $this->deliveredClaimOrder(quantity: 2);

        foreach ($order->options as $option) {
            $option->forceFill(['delivered_at' => now()->subDays(10)])->save();
        }

        return $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);
    }

    /**
     * 신청 기간이 지난 주문은 고객 반품이 막히지만 운영자 반품 접수는 받습니다. 기본은 접수와 함께 승인이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_return_is_accepted_after_the_request_period, admin_request_is_approved_with_the_request_by_default
     */
    public function test_admin_files_a_return_after_the_request_period_and_approves_it(): void
    {
        $order = $this->staleDeliveredOrder();
        $option = $order->options->first();

        try {
            app(UserClaimService::class)->requestReturn($order, [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
                'requested_by' => $order->user_id,
            ]);
            $this->fail('고객 반품은 신청 기간이 지나면 거절돼야 한다.');
        } catch (OrderClaimException) {
            // 고객 축 거절 — 기대한 결과
        }

        $response = $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'seller_wrong_shipment',
        ]);

        $response->assertCreated()->assertJsonPath('data.approval_skipped', null);

        $return = OrderReturn::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($return);
        $this->assertSame((int) $this->admin->id, (int) $return->requested_by_admin);
        $this->assertNull($return->requested_by);
        $this->assertSame(ReturnStatusEnum::APPROVED, $return->return_status);
        $this->assertTrue($return->isAdminRequested());
    }

    /**
     * 「접수와 함께 승인」 을 끄면 신청 상태로 남고, 운영자가 고친 배송비와 청구 방식이 그대로 기록됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_exchange_keeps_the_requested_status_and_overridden_fee
     */
    public function test_admin_exchange_keeps_requested_status_and_overridden_fee(): void
    {
        $order = $this->staleDeliveredOrder();
        $option = $order->options->first();

        $this->assertFiled($this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'claim_shipping_fee' => 3000,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL->value,
            'approve' => false,
        ]));

        $exchange = OrderExchange::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertEquals(3000, (float) $exchange->claim_shipping_fee);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
    }

    /**
     * 「무료」 는 배송비 0 에 청구 방식 없음이다. 미리보기도 같은 값을 말한다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_free_charge_zeroes_the_fee_in_preview_and_request
     */
    public function test_free_charge_zeroes_the_fee_in_preview_and_request(): void
    {
        $order = $this->staleDeliveredOrder();
        $option = $order->options->first();
        $body = [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'free',
        ];

        $preview = $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/claims/preview", $body + ['type' => 'return'])
            ->assertOk()
            ->json('data');
        $this->assertEquals(0, (float) ($preview['claim_shipping_fee']['amount'] ?? $preview['claim_shipping_fee'] ?? 0));

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/returns", $body + ['approve' => false])
            ->assertCreated();

        $return = OrderReturn::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertEquals(0, (float) $return->claim_shipping_fee);
        $this->assertNull($return->charge_method);
    }

    /**
     * 아직 보내지 않은 상품에는 반품(교환)을 받지 않습니다. (D5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=not_shipped
     *
     * @effects admin_physical_gate_rejects_the_wrong_side
     */
    public function test_physical_gate_rejects_a_return_for_unshipped_items(): void
    {
        $paid = $this->paidClaimOrder();

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$paid->id}/returns", [
            'items' => [['order_option_id' => $paid->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ])->assertStatus(422);
    }

    /**
     * 이미 보낸 상품에는 취소·환불을 받지 않습니다. (D5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel_refund, gate_side=shipped
     *
     * @effects admin_physical_gate_rejects_the_wrong_side
     */
    public function test_physical_gate_rejects_a_cancel_refund_for_shipped_items(): void
    {
        $delivered = $this->staleDeliveredOrder();

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$delivered->id}/cancel-refund", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $delivered->options->first()->id, 'cancel_quantity' => 1]],
        ])->assertStatus(422);
    }

    /**
     * 설정의 취소 가능 상태 밖(상품준비중)이어도 아직 보내지 않은 상품이면 운영자 취소·환불 접수를 받습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel_refund, gate_side=not_shipped
     *
     * @effects admin_cancel_refund_ignores_the_cancellable_status_setting
     */
    public function test_cancel_refund_accepts_preparing_items_regardless_of_settings(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.cancellable_statuses', ['payment_complete']);
        $order = $this->moveOrderTo($this->paidClaimOrder(quantity: 2), OrderStatusEnum::PREPARING);

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/cancel-refund", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
        ])->assertCreated();

        $refund = OrderRefund::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundSourceTypeEnum::ADMIN, $refund->refund_source_type);
    }

    /**
     * `claims.create` 가 없는 운영자는 직권 접수를 할 수 없습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_request_requires_the_claims_create_permission
     */
    public function test_request_requires_the_claims_create_permission(): void
    {
        $order = $this->staleDeliveredOrder();
        $operator = $this->createAdminUser(['sirsoft-ecommerce.claims.read', 'sirsoft-ecommerce.claims.update-status']);

        $this->actingAs($operator)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ])->assertForbidden();
    }

    /**
     * 판매자가 접수한 신청은 고객이 철회·수정할 수 없고, 운영자는 고객 요청으로 대신 철회할 수 있습니다. (D8 · S14)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_requested_claim_is_not_withdrawable_by_the_customer, admin_withdraws_on_behalf_with_a_required_reason
     */
    public function test_admin_requested_claim_blocks_customer_withdraw_but_allows_withdraw_on_behalf(): void
    {
        $order = $this->staleDeliveredOrder();

        $this->assertFiled($this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
            'approve' => false,
        ]));

        $return = OrderReturn::query()->where('order_id', $order->id)->latest('id')->first();

        try {
            app(OrderReturnService::class)->withdraw($return);
            $this->fail('판매자 접수 건은 고객이 철회할 수 없어야 한다.');
        } catch (OrderClaimException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.claim_admin_requested_not_editable', $e->getMessageKey());
        }

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/withdraw", [])
            ->assertStatus(422);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/withdraw", [
            'reason' => '고객 전화 요청',
        ])->assertOk();

        $fresh = $return->fresh();
        $this->assertSame(ReturnStatusEnum::WITHDRAWN, $fresh->return_status);
        $this->assertSame((int) $this->admin->id, (int) $fresh->withdrawn_by_admin);
        $this->assertSame('고객 전화 요청', $fresh->withdraw_reason);
    }

    /**
     * 운영자는 신청 사유를 판매자 귀책(고객 비노출) 사유로 고칠 수 있습니다. (S15)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_updates_the_request_reason_to_a_seller_fault_reason
     */
    public function test_admin_updates_the_request_reason_to_a_seller_fault_reason(): void
    {
        $order = $this->staleDeliveredOrder();

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'operator' => true,
        ]);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}", [
            'reason_code' => 'seller_wrong_shipment',
            'reason_detail' => '다른 색상 발송',
        ])->assertOk();

        $fresh = $return->fresh();
        $this->assertSame('seller_wrong_shipment', $fresh->reason_code);
        $this->assertSame('다른 색상 발송', $fresh->reason_detail);
    }

    /**
     * 완료 때 재고를 복구하지 않은 상품 행은 뒤늦게 복구할 수 있고, 두 번째 요청은 거절됩니다. (S13 — 멱등)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_restores_stock_later_once
     */
    public function test_restore_stock_later_is_idempotent(): void
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => false],
                'create_refund_request' => false,
            ],
        ])->assertOk();

        $this->assertFalse((bool) $item->fresh()->is_stock_restored);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/items/restore-stock", [
            'items' => [$item->id],
        ])->assertOk()->assertJsonPath('data.restored.0.id', $item->id);

        $this->assertTrue((bool) $item->fresh()->is_stock_restored);

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/items/restore-stock", [
            'items' => [$item->id],
        ])->assertStatus(422);
    }

    /**
     * 목록은 접수 출처로 거를 수 있습니다. (판매자 접수 / 고객 접수)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_claim_list_filters_by_request_source
     */
    public function test_list_filters_by_request_source(): void
    {
        $order = $this->staleDeliveredOrder();
        $options = $order->options;

        $this->assertFiled($this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
            'approve' => false,
        ]));

        $customer = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'operator' => true,
        ]);

        $adminRows = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&request_source=admin')
            ->assertOk()->json('data.data');
        $customerRows = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&request_source=customer')
            ->assertOk()->json('data.data');

        $this->assertCount(1, $adminRows);
        $this->assertTrue((bool) $adminRows[0]['is_admin_requested']);
        $this->assertContains($customer->return_number, array_column($customerRows, 'claim_number'));
    }

    /**
     * 수량 잠금은 운영자에게도 그대로다 — 진행 중인 신청이 선점한 수량을 넘는 직권 접수는 거절하고,
     * 남은 수량까지는 받는다. (D5 는 신청 기간·상태 설정만 풀고 수량 잠금은 풀지 않는다)
     *
     * 선점은 고객의 직접 환불신청으로 건다 — 교환·반품 신청은 접수 때 수량을 코스 상태 행으로 떼어 내므로
     * 원 행의 수량 자체가 줄지만, 직접 환불신청은 상품 상태를 바꾸지 않아 잠금만이 수량을 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel_refund, gate_side=not_shipped
     *
     * @effects admin_request_follows_the_quantity_lock
     */
    public function test_admin_request_follows_the_quantity_lock(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);
        $option = $order->options->first();

        // 고객의 직접 환불신청이 2개 중 1개를 선점한다.
        $this->makeDirectRefund($order, $option, 1);

        $rejected = $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/cancel-refund", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 2]],
        ]);

        $rejected->assertStatus(422);
        // 영어 문구는 `단수|복수` 꼴이라 요청 수로 고른 문장과 비교한다 (2026-10-08 결정: 1이면 단수).
        $this->assertSame(
            trans_choice('sirsoft-ecommerce::exceptions.claim_quantity_locked', 2, ['requested' => 2, 'available' => 1]),
            $rejected->json('message'),
        );
        $this->assertSame(1, OrderRefund::query()->where('order_id', $order->id)->count(), '잠긴 수량을 넘는 접수가 신청서를 남겼습니다.');

        // 남은 1개는 받는다 — 잠금이 행 전체를 막는 것이 아니다.
        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/cancel-refund", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(2, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 옛 주문 취소 경로(`/orders/{order}/cancel`)도 수량 잠금 사유를 요청 수로 고른 한 문장으로 돌려줍니다.
     *
     * 영어 사유 문구는 `단수|복수` 꼴이라, 키를 `__()` 로 번역하면 두 문장이 `|` 로 이어진 채
     * `errors.detail` 에 실린다. 직권 접수 경로(`cancel-refund`)와 같은 한 문장이어야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel_refund, gate_side=not_shipped
     *
     * @effects legacy_cancel_quantity_lock_reason_is_one_plural_form
     */
    public function test_legacy_cancel_route_quantity_lock_reason_is_one_plural_form(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);
        $option = $order->options->first();
        $this->makeDirectRefund($order, $option, 1);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $admin->forceFill(['language' => 'en'])->save();

        $rejected = $this->actingAs($admin)->postJson($this->base()."/orders/{$order->id}/cancel", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 2]],
        ]);

        $rejected->assertStatus(422);
        $detail = (string) $rejected->json('errors.detail');
        $this->assertStringNotContainsString('|', $detail, '단수·복수 두 문장이 이어져 나왔습니다.');
        $this->assertSame(
            trans_choice('sirsoft-ecommerce::exceptions.claim_quantity_locked', 2, ['requested' => 2, 'available' => 1], 'en'),
            $detail,
        );
    }

    /**
     * 「접수와 함께 승인」 에서 승인이 막히면(교환품 재고가 그 사이 바닥남) 접수는 남기고 응답에
     * `approval_skipped` 로 사유를 싣는다. (D6 — 접수와 승인은 다른 사실이다)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_blocked_approval_keeps_the_request_and_reports_the_reason
     */
    public function test_blocked_approval_keeps_the_request_and_reports_the_reason(): void
    {
        $order = $this->staleDeliveredOrder();
        $option = $order->options->first();

        // 접수 뒤 승인 직전에 재고가 바닥난다 — 접수 검사는 통과하고 승인의 재고 차감만 실패한다.
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.before_status_change', function ($exchange, ?string $from, string $to) use ($option): void {
            if ($to === ExchangeStatusEnum::APPROVED->value) {
                ProductOption::query()->whereKey($option->product_option_id)->update(['stock_quantity' => 0]);
            }
        });

        $response = $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => 'free',
        ]);

        $this->assertFiled($response);
        $response->assertJsonPath('data.payment_requested', false)
            ->assertJsonPath('data.approval_skipped.reason', __('sirsoft-ecommerce::exceptions.claim_new_option_out_of_stock'));

        $exchange = OrderExchange::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($exchange, '승인이 막히자 접수까지 되돌려졌습니다.');
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertNull($exchange->approved_at);
        $this->assertSame((int) $this->admin->id, (int) $exchange->requested_by_admin);
    }

    /**
     * 추가결제가 필요한 건은 「접수와 함께 승인」 이어도 승인되지 않고 결제대기로 간다 — 결제 요청이
     * 나가고 승인 시각은 비어 있다. (D6 · 기존 승인-결제 게이트)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects admin_approval_with_an_unpaid_charge_goes_to_payment_pending
     */
    public function test_approval_with_an_unpaid_charge_goes_to_payment_pending(): void
    {
        $order = $this->staleDeliveredOrder();

        $response = $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'claim_shipping_fee' => 3000,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL->value,
            'approve' => true,
        ]);

        $this->assertFiled($response);
        $response->assertJsonPath('data.payment_requested', true)
            ->assertJsonPath('data.approval_skipped', null);

        $exchange = OrderExchange::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame(ExchangeStatusEnum::PAYMENT_PENDING, $exchange->exchange_status);
        $this->assertNull($exchange->approved_at, '미수납 건이 승인된 것으로 섰습니다.');

        $payment = ClaimPayment::query()
            ->where('payable_type', ClaimTypeEnum::EXCHANGE->value)
            ->where('payable_id', $exchange->id)
            ->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->isPending());
    }

    /**
     * 다른 주문의 상품으로는 접수할 수 없습니다 — 반품·취소 두 입구 모두 422 이고 아무것도 남지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects admin_request_rejects_an_option_of_another_order
     */
    public function test_request_rejects_an_option_of_another_order(): void
    {
        $order = $this->staleDeliveredOrder();
        $foreign = $this->staleDeliveredOrder();

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $foreign->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'free',
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.order_option_id']);

        $paid = $this->paidClaimOrder();
        $foreignPaid = $this->paidClaimOrder();

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$paid->id}/cancel-refund", [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $foreignPaid->options->first()->id, 'cancel_quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.order_option_id']);

        $this->assertSame(0, OrderReturn::query()->whereIn('order_id', [$order->id, $foreign->id])->count());
        $this->assertSame(0, OrderRefund::query()->whereIn('order_id', [$paid->id, $foreignPaid->id])->count());
    }

    /**
     * 아직 보내지 않은(상품준비중) 상품에는 교환을 받지 않습니다. (D5 — 물리 게이트는 교환에도 같다)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=not_shipped
     *
     * @effects admin_physical_gate_rejects_the_wrong_side
     */
    public function test_physical_gate_rejects_an_exchange_for_preparing_items(): void
    {
        $order = $this->moveOrderTo($this->paidClaimOrder(), OrderStatusEnum::PREPARING);

        $this->actingAs($this->admin)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => 'free',
        ])->assertStatus(422);

        $this->assertSame(0, OrderExchange::query()->where('order_id', $order->id)->count());
    }
}
