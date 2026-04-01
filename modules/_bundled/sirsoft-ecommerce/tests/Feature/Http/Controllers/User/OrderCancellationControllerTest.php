<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용자 주문 취소 API Feature 테스트
 *
 * 사용자의 주문 취소 및 환불 예상 금액 조회 API를 테스트합니다.
 */
class OrderCancellationControllerTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 사용자 취소는 cancelPg 기본값이 true이므로 PG 환불 훅 모킹 필요
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function () {
            return [
                'success' => true,
                'transaction_id' => 'TEST_USER_TXN_' . time(),
                'error_code' => null,
                'error_message' => null,
            ];
        }, 10);
    }

    /**
     * 취소/환불 시퀀스를 생성합니다.
     *
     * @return void
     */
    private function createCancelSequences(): void
    {
        $cancelConfig = SequenceType::CANCEL->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::CANCEL->value],
            [
                'algorithm' => $cancelConfig['algorithm']->value,
                'prefix' => $cancelConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $cancelConfig['max_value'],
                'cycle' => false,
                'pad_length' => $cancelConfig['pad_length'],
            ]
        );

        $refundConfig = SequenceType::REFUND->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::REFUND->value],
            [
                'algorithm' => $refundConfig['algorithm']->value,
                'prefix' => $refundConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $refundConfig['max_value'],
                'cycle' => false,
                'pad_length' => $refundConfig['pad_length'],
            ]
        );
    }

    /**
     * 환불 예상 금액 조회 성공 테스트
     *
     * 유효한 주문 옵션과 수량으로 환불 예상 금액 조회 시 200을 반환하는지 확인합니다.
     *
     * @return void
     */
    public function test_user_estimate_refund(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 40000,
            'total_amount' => 40000,
            'total_paid_amount' => 40000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option1 = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $option2 = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option1->id, 'cancel_quantity' => 1],
                ],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * 전체 취소 성공 테스트
     *
     * items 없이 취소 요청 시 전체 주문이 취소되고 주문 상태가 CANCELLED로 변경되는지 확인합니다.
     *
     * @return void
     */
    public function test_user_full_cancel(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 40000,
            'total_amount' => 40000,
            'total_paid_amount' => 40000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 40000,
            'subtotal_price' => 40000,
            'subtotal_paid_amount' => 40000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 40000,
            'paid_amount_base' => 40000,
            'paid_at' => now(),
        ]);

        OrderShipping::factory()->forOrder($order)->create([
            'base_shipping_amount' => 0,
            'total_shipping_amount' => 0,
        ]);

        $this->createCancelSequences();

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'changed_mind',
            ]
        );

        $response->assertOk();

        $this->assertEquals(
            OrderStatusEnum::CANCELLED,
            $order->fresh()->order_status
        );
    }

    /**
     * 부분 취소 성공 테스트
     *
     * items를 지정하여 취소 요청 시 해당 옵션만 부분 취소되는지 확인합니다.
     *
     * @return void
     */
    public function test_user_partial_cancel(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 40000,
            'total_amount' => 40000,
            'total_paid_amount' => 40000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option1 = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $option2 = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 40000,
            'paid_amount_base' => 40000,
            'paid_at' => now(),
        ]);

        OrderShipping::factory()->forOrder($order)->create([
            'base_shipping_amount' => 0,
            'total_shipping_amount' => 0,
        ]);

        $this->createCancelSequences();

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'order_mistake',
                'items' => [
                    ['order_option_id' => $option1->id, 'cancel_quantity' => 1],
                ],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * 다른 사용자의 주문 취소 시도 시 403/404 반환 테스트
     *
     * 본인이 아닌 다른 사용자의 주문을 취소하려 하면 404를 반환하는지 확인합니다.
     * (FormRequest에서 user_id 불일치 시 abort(404) 처리)
     *
     * @return void
     */
    public function test_user_cancel_others_order_forbidden(): void
    {
        $owner = $this->createUser();
        $attacker = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $owner->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($attacker)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'changed_mind',
            ]
        );

        $response->assertNotFound();
    }

    /**
     * 취소 불가능한 상태의 주문 취소 시도 시 422 반환 테스트
     *
     * 배송 완료(DELIVERED) 상태의 주문을 취소하려 하면 422를 반환하는지 확인합니다.
     *
     * @return void
     */
    public function test_user_cancel_uncancellable_status(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::DELIVERED,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'changed_mind',
            ]
        );

        $response->assertUnprocessable();
    }

    /**
     * 환불 예상 금액 조회 시 유효하지 않은 수량으로 요청 시 422 반환 테스트
     *
     * 주문 수량을 초과하는 취소 수량으로 요청하면 422를 반환하는지 확인합니다.
     *
     * @return void
     */
    public function test_user_estimate_refund_validation(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 40000,
            'total_amount' => 40000,
            'total_paid_amount' => 40000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 2,
            'unit_price' => 20000,
            'subtotal_price' => 40000,
            'subtotal_paid_amount' => 40000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option->id, 'cancel_quantity' => 5],
                ],
            ]
        );

        $response->assertUnprocessable();
    }

    /**
     * 미인증 사용자의 주문 취소 시도 시 401 반환 테스트
     *
     * 인증 없이 주문 취소 API를 호출하면 401을 반환하는지 확인합니다.
     *
     * @return void
     */
    public function test_user_cancel_unauthenticated(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'changed_mind',
            ]
        );

        $response->assertUnauthorized();
    }

    // ========================================
    // estimateRefund() - 환불 우선순위 테스트
    // ========================================

    /**
     * 사용자가 refund_priority=pg_first로 환불 예상금액을 조회하면 PG 우선 배분 결과를 반환한다.
     *
     * @return void
     */
    public function test_user_estimate_refund_with_refund_priority_pg_first(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 15000,
            'total_points_used_amount' => 5000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option->id, 'cancel_quantity' => 1],
                ],
                'refund_priority' => 'pg_first',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.refund_priority', 'pg_first');
    }

    /**
     * 사용자가 refund_priority=points_first로 환불 예상금액을 조회하면 포인트 우선 배분 결과를 반환한다.
     *
     * @return void
     */
    public function test_user_estimate_refund_with_refund_priority_points_first(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 15000,
            'total_points_used_amount' => 5000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option->id, 'cancel_quantity' => 1],
                ],
                'refund_priority' => 'points_first',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.refund_priority', 'points_first');
    }

    // ========================================
    // estimateRefund() - 응답 상세 필드 테스트
    // ========================================

    /**
     * 배송 정보가 있는 주문의 환불 예상금액 응답에 shipping_details 키가 포함된다.
     *
     * @return void
     */
    public function test_user_estimate_refund_includes_shipping_details(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        OrderShipping::factory()->forOrder($order)->create([
            'order_option_id' => $option->id,
            'base_shipping_amount' => 3000,
            'total_shipping_amount' => 3000,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option->id, 'cancel_quantity' => 1],
                ],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['shipping_details'],
            ]);
    }

    /**
     * 쿠폰이 적용된 주문의 환불 예상금액 응답에 restored_coupons 키가 포함된다.
     *
     * @return void
     */
    public function test_user_estimate_refund_includes_restored_coupons(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 27000,
            'total_coupon_discount_amount' => 3000,
            'total_order_coupon_discount_amount' => 3000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $option = OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal_price' => 30000,
            'subtotal_paid_amount' => 30000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // 쿠폰 발급 레코드 생성
        $coupon = \Modules\Sirsoft\Ecommerce\Models\Coupon::create([
            'name' => '사용자 테스트 쿠폰',
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 3000,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 100,
            'issued_count' => 1,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'is_combinable' => false,
        ]);

        $couponIssue = \Modules\Sirsoft\Ecommerce\Models\CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'USER-TEST-COUPON-001',
            'status' => CouponIssueRecordStatus::USED->value,
            'issued_at' => now(),
            'used_at' => now(),
            'order_id' => $order->id,
            'discount_amount' => 3000,
        ]);

        // 프로모션 스냅샷 설정
        $order->update([
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => [$couponIssue->id],
                'order_promotions' => [
                    'coupons' => [
                        [
                            'coupon_issue_id' => $couponIssue->id,
                            'discount_type' => 'fixed',
                            'discount_value' => 3000,
                            'min_order_amount' => 0,
                            'target_type' => 'order_amount',
                            'target_scope' => 'all',
                        ],
                    ],
                ],
                'product_promotions' => ['coupons' => []],
            ],
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/estimate-refund",
            [
                'items' => [
                    ['order_option_id' => $option->id, 'cancel_quantity' => 1],
                ],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['restored_coupons'],
            ]);
    }

    // ========================================
    // cancel() - 환불 우선순위 적용 테스트
    // ========================================

    /**
     * 사용자가 refund_priority를 지정하여 취소하면 성공한다.
     *
     * @return void
     */
    public function test_user_cancel_with_refund_priority(): void
    {
        $user = $this->createUser();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 15000,
            'total_points_used_amount' => 5000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 15000,
            'paid_amount_base' => 15000,
            'paid_at' => now(),
        ]);

        OrderShipping::factory()->forOrder($order)->create([
            'base_shipping_amount' => 0,
            'total_shipping_amount' => 0,
        ]);

        $this->createCancelSequences();

        $response = $this->actingAs($user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            [
                'reason' => 'changed_mind',
                'refund_priority' => 'points_first',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true);
    }
}
