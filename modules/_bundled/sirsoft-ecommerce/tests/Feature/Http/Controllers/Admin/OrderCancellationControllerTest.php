<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 취소 컨트롤러 Feature 테스트
 *
 * 관리자 주문 취소 API 엔드포인트를 검증합니다.
 * - 환불 예상금액 조회 (estimate-refund)
 * - 전체취소 / 부분취소 (cancel)
 * - PG 연동 취소 옵션
 * - 유효성 검증 및 에러 케이스
 */
class OrderCancellationControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // 이전 테스트에서 저장된 환경설정 파일 제거 (RefreshDatabase 는 storage 롤백 안 함)
        $settingsDir = storage_path('app/modules/sirsoft-ecommerce/settings');
        if (is_dir($settingsDir)) {
            foreach (glob($settingsDir.'/*.json') as $file) {
                @unlink($file);
            }
        }
        app(\Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService::class)->clearCache();

        // g7_settings.modules Config cache 도 제거 (CoreServiceProvider 가 boot 시 로드)
        \Illuminate\Support\Facades\Config::set('g7_settings.modules.sirsoft-ecommerce', []);
    }

    /**
     * 취소/환불 시퀀스를 생성합니다.
     *
     * TestingSeeder는 ORDER 시퀀스만 생성하므로,
     * 취소/환불 번호 채번을 위해 CANCEL과 REFUND 시퀀스를 추가합니다.
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
     * 결제 완료 상태의 주문과 관련 데이터를 생성합니다.
     *
     * @param  int  $optionCount  주문 옵션 개수
     * @param  int  $unitPrice  옵션 단가
     * @param  int  $quantity  옵션 수량
     * @param  bool  $withPayment  결제 정보 생성 여부
     * @param  bool  $withShipping  배송 정보 생성 여부
     * @return array{order: Order, options: array<OrderOption>, payment: ?OrderPayment, shipping: ?OrderShipping}
     */
    private function createPaidOrderWithOptions(
        int $optionCount = 1,
        int $unitPrice = 20000,
        int $quantity = 1,
        bool $withPayment = false,
        bool $withShipping = false,
    ): array {
        $user = User::factory()->create();
        $totalAmount = $unitPrice * $quantity * $optionCount;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $totalAmount,
            'total_amount' => $totalAmount,
            'total_paid_amount' => $totalAmount,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        // 스냅샷 가격은 factory default 가 random(5000~100000) 이므로 명시 고정
        // (OrderAdjustmentService::buildRecalcInput 가 snapshot.selling_price 를 재계산 기준가로
        // 사용 — 원 총액보다 크면 "환불 음수" 에러 발생. 단위 테스트는 faker 상태가 테스트 간
        // 변하므로 suite 실행에서만 재현되어 state leak 처럼 보임.)
        $snapshotOverride = [
            'product_snapshot' => [
                'id' => null, 'name' => ['ko' => 't', 'en' => 't'], 'product_code' => null,
                'sku' => null, 'brand_id' => null, 'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                'currency_code' => 'KRW', 'stock_quantity' => 100, 'tax_status' => 'taxable',
                'tax_rate' => 10, 'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
            ],
            'option_snapshot' => [
                'id' => null, 'option_code' => null, 'option_values' => null, 'option_name' => 't',
                'price_adjustment' => 0, 'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                'currency_code' => 'KRW', 'stock_quantity' => 100, 'weight' => 0, 'volume' => 0,
            ],
        ];

        $options = [];
        for ($i = 0; $i < $optionCount; $i++) {
            $subtotalPrice = $unitPrice * $quantity;
            $options[] = OrderOption::factory()->forOrder($order)->create(array_merge([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal_price' => $subtotalPrice,
                'subtotal_paid_amount' => $subtotalPrice,
                'subtotal_discount_amount' => 0,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            ], $snapshotOverride));
        }

        $payment = null;
        if ($withPayment) {
            $payment = OrderPayment::factory()->forOrder($order)->create([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_amount_local' => $totalAmount,
                'paid_amount_base' => $totalAmount,
                'paid_at' => now(),
            ]);
        }

        $shipping = null;
        if ($withShipping) {
            $shipping = OrderShipping::factory()->forOrder($order)->create([
                'order_option_id' => $options[0]->id,
                'base_shipping_amount' => 0,
                'total_shipping_amount' => 0,
            ]);
        }

        return compact('order', 'options', 'payment', 'shipping');
    }

    // ========================================
    // estimateRefund() 테스트
    // ========================================

    /**
     * 관리자가 유효한 취소 아이템으로 환불 예상금액을 조회할 수 있다.
     */
    public function test_admin_estimate_refund(): void
    {
        // Given: 결제 완료된 주문 (옵션 2개, 각 20000원)
        $data = $this->createPaidOrderWithOptions(optionCount: 2, unitPrice: 20000, quantity: 1);
        $order = $data['order'];
        $options = $data['options'];

        // When: 첫 번째 옵션에 대해 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
            ]);

        // Then: 성공 응답 및 환불 예상금액 포함 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'refund_amount',
                ],
            ]);
    }

    /**
     * 환불 예상금액 조회 시 items가 비어있으면 422 검증 에러를 반환한다.
     */
    public function test_admin_estimate_refund_validation(): void
    {
        // Given: 결제 완료된 주문
        $data = $this->createPaidOrderWithOptions(optionCount: 1);
        $order = $data['order'];

        // When: items 없이 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", []);

        // Then: 422 검증 에러 반환 (ResponseHelper::error 응답 형식)
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ========================================
    // cancelOrder() 테스트 - 전체취소
    // ========================================

    /**
     * 관리자가 전체취소를 실행하면 주문 상태가 CANCELLED로 변경된다.
     */
    public function test_admin_full_cancel(): void
    {
        // Given: 결제 완료된 주문 + 결제/배송 정보 + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
        );
        $order = $data['order'];

        // When: 전체취소 요청 (PG 연동 없이)
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => false,
            ]);

        // Then: 성공 응답 및 주문 상태 CANCELLED 확인
        $response->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    // ========================================
    // cancelOrder() 테스트 - 부분취소
    // ========================================

    /**
     * 관리자가 부분취소를 실행하면 지정된 옵션만 취소된다.
     */
    public function test_admin_partial_cancel(): void
    {
        // Given: 결제 완료된 주문 (옵션 2개) + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 2,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
        );
        $order = $data['order'];
        $options = $data['options'];

        // When: 첫 번째 옵션만 부분취소 요청 (PG 연동 없이)
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
                'reason' => 'order_mistake',
                'cancel_pg' => false,
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // cancelOrder() 테스트 - PG 연동
    // ========================================

    /**
     * cancel_pg=true로 취소 요청 시 PG 연동 취소가 시도된다.
     * (테스트 환경에서 PG 리스너 없음 → 훅 반환값 null → PG 미등록 처리로 취소 성공)
     */
    public function test_admin_cancel_with_pg(): void
    {
        // Given: 결제 완료된 주문 + 결제 정보 + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 30000,
            quantity: 1,
            withPayment: true,
        );
        $order = $data['order'];

        // Mock PG 환불 훅 리스너 등록 (테스트 환경에서 PG 환불 성공 시뮬레이션)
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) {
            return [
                'success' => true,
                'transaction_id' => 'TEST_TXN_' . time(),
                'error_code' => null,
                'error_message' => null,
            ];
        }, 10);

        // When: PG 결제 취소 옵션 활성화하여 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        // Then: 성공 응답 (PG 환불 훅 성공)
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * cancel_pg=false로 취소 요청 시 PG 연동 없이 취소가 처리된다.
     */
    public function test_admin_cancel_without_pg(): void
    {
        // Given: 결제 완료된 주문 + 결제 정보 + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 25000,
            quantity: 1,
            withPayment: true,
        );
        $order = $data['order'];

        // When: PG 결제 취소 옵션 비활성화하여 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'admin_cancel',
                'cancel_pg' => false,
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // cancelOrder() 테스트 - 에러 케이스
    // ========================================

    /**
     * 배송 중(SHIPPING) 상태의 주문은 취소할 수 없어 422 에러를 반환한다.
     */
    public function test_admin_cancel_invalid_order_status(): void
    {
        // Given: 배송 중 상태의 주문
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::SHIPPING,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now()->subDays(2),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
            ]);

        // Then: 422 검증 에러 (취소 불가 상태)
        $response->assertStatus(422);
    }

    /**
     * 배송 중(SHIPPING) 상태 주문 취소 시 422 에러를 반환한다.
     */
    public function test_admin_cancel_invalid_status_returns_error(): void
    {
        // Given: 배송 중 상태의 주문
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::SHIPPING,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now()->subDays(2),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
            ]);

        // Then: 422 에러 + success: false
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * 환경설정에서 preparing 상태까지 취소 가능으로 설정하면 preparing 주문도 취소된다.
     */
    public function test_admin_cancel_respects_settings_cancellable_statuses(): void
    {
        // Given: 상품준비중(PREPARING) 상태의 주문 + 환경설정에 preparing 추가
        $this->createCancelSequences();

        $settingsService = app(\Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService::class);
        $settingsService->saveSettings(['order_settings' => ['cancellable_statuses' => ['payment_complete', 'preparing']]]);

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PREPARING,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);
        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal_price' => 20000,
            'subtotal_paid_amount' => 20000,
            'subtotal_discount_amount' => 0,
            'option_status' => OrderStatusEnum::PREPARING,
        ]);
        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 20000,
            'paid_amount_base' => 20000,
            'paid_at' => now(),
        ]);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => false,
            ]);

        // Then: 성공 응답 + 주문 상태 CANCELLED
        $response->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 환경설정 미설정 시 preparing 상태 주문은 취소 불가하다 (기본값 사용).
     */
    public function test_admin_cancel_preparing_order_fails_without_settings(): void
    {
        // Given: 상품준비중(PREPARING) 상태의 주문 (환경설정 미변경 — setUp 에서 파일 초기화됨)
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PREPARING,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
            ]);

        // Then: 422 에러 (기본 취소 가능 상태에 preparing 미포함)
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * 이미 취소된 주문을 다시 취소 요청 시 422 에러를 반환한다.
     */
    public function test_admin_cancel_already_cancelled_order_returns_error(): void
    {
        // Given: 이미 취소된 주문
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::CANCELLED,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 20000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 20000,
            'cancellation_count' => 1,
            'paid_at' => now()->subDay(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
            ]);

        // Then: 422 에러
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * 존재하지 않는 주문번호로 취소 요청 시 404 에러를 반환한다.
     */
    public function test_admin_cancel_nonexistent_order(): void
    {
        // When: 존재하지 않는 주문번호로 취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/orders/NONEXISTENT-ORDER-999/cancel', [
                'type' => 'full',
                'reason' => 'changed_mind',
            ]);

        // Then: 404 에러
        $response->assertNotFound();
    }

    // ========================================
    // estimateRefund() - 환불 우선순위 테스트
    // ========================================

    /**
     * 관리자가 refund_priority=pg_first로 환불 예상금액을 조회하면 PG 우선 배분 결과를 반환한다.
     */
    public function test_admin_estimate_refund_with_refund_priority_pg_first(): void
    {
        // Given: 결제 완료된 주문 (포인트 사용 포함)
        $data = $this->createPaidOrderWithOptions(optionCount: 1, unitPrice: 20000, quantity: 1, withPayment: true);
        $order = $data['order'];
        $options = $data['options'];

        // 포인트 사용 설정
        $order->update([
            'total_points_used_amount' => 5000,
            'total_paid_amount' => 15000,
            'total_amount' => 20000,
        ]);

        // When: pg_first 우선순위로 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
                'refund_priority' => 'pg_first',
            ]);

        // Then: 성공 응답 및 refund_priority 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.refund_priority', 'pg_first');
    }

    /**
     * 관리자가 refund_priority=points_first로 환불 예상금액을 조회하면 포인트 우선 배분 결과를 반환한다.
     */
    public function test_admin_estimate_refund_with_refund_priority_points_first(): void
    {
        // Given: 결제 완료된 주문 (포인트 사용 포함)
        $data = $this->createPaidOrderWithOptions(optionCount: 1, unitPrice: 20000, quantity: 1, withPayment: true);
        $order = $data['order'];
        $options = $data['options'];

        $order->update([
            'total_points_used_amount' => 5000,
            'total_paid_amount' => 15000,
            'total_amount' => 20000,
        ]);

        // When: points_first 우선순위로 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
                'refund_priority' => 'points_first',
            ]);

        // Then: 성공 응답 및 refund_priority 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.refund_priority', 'points_first');
    }

    // ========================================
    // estimateRefund() - 응답 상세 필드 테스트
    // ========================================

    /**
     * 환불 예상금액 응답에 배송비 상세(shipping_details) 키가 포함된다.
     */
    public function test_admin_estimate_refund_includes_shipping_details(): void
    {
        // Given: 결제 완료된 주문 + 배송 정보
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
        );
        $order = $data['order'];
        $options = $data['options'];

        // When: 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
            ]);

        // Then: shipping_details 키 존재 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['shipping_details'],
            ]);
    }

    /**
     * 쿠폰이 적용된 주문의 환불 예상금액 응답에 restored_coupons 키가 포함된다.
     */
    public function test_admin_estimate_refund_includes_restored_coupons(): void
    {
        // Given: 결제 완료된 주문 + 쿠폰 적용 스냅샷
        $data = $this->createPaidOrderWithOptions(optionCount: 1, unitPrice: 30000, quantity: 1, withPayment: true);
        $order = $data['order'];
        $options = $data['options'];

        // 쿠폰 발급 레코드 생성
        $coupon = \Modules\Sirsoft\Ecommerce\Models\Coupon::create([
            'name' => '테스트 쿠폰',
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
            'user_id' => $order->user_id,
            'coupon_code' => 'TEST-COUPON-001',
            'status' => CouponIssueRecordStatus::USED->value,
            'issued_at' => now(),
            'used_at' => now(),
            'order_id' => $order->id,
            'discount_amount' => 3000,
        ]);

        // 프로모션 스냅샷에 쿠폰 정보 설정
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
            'total_coupon_discount_amount' => 3000,
            'total_order_coupon_discount_amount' => 3000,
            'total_paid_amount' => 27000,
            'total_amount' => 30000,
        ]);

        // When: 전체 취소 대상으로 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
            ]);

        // Then: restored_coupons 키 존재 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['restored_coupons'],
            ]);
    }

    /**
     * 포인트 사용 주문의 환불 예상금액 응답에 잔여 잔액 키가 포함된다.
     */
    public function test_admin_estimate_refund_includes_remaining_balances(): void
    {
        // Given: 결제 완료된 주문 (포인트 사용 포함, 옵션 2개)
        $data = $this->createPaidOrderWithOptions(optionCount: 2, unitPrice: 20000, quantity: 1, withPayment: true);
        $order = $data['order'];
        $options = $data['options'];

        $order->update([
            'total_points_used_amount' => 10000,
            'total_paid_amount' => 30000,
            'total_amount' => 40000,
        ]);

        // When: 부분취소 (1개 옵션만) 환불 예상금액 조회
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => [
                    [
                        'order_option_id' => $options[0]->id,
                        'cancel_quantity' => 1,
                    ],
                ],
            ]);

        // Then: remaining_pg_balance, remaining_points_balance 키 존재 확인
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'remaining_pg_balance',
                    'remaining_points_balance',
                ],
            ]);
    }

    // ========================================
    // cancelOrder() - 환불 우선순위 적용 테스트
    // ========================================

    /**
     * 관리자가 refund_priority를 지정하여 취소하면 성공한다.
     */
    public function test_admin_cancel_with_refund_priority(): void
    {
        // Given: 결제 완료된 주문 + 포인트 사용 + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
        );
        $order = $data['order'];

        $order->update([
            'total_points_used_amount' => 5000,
            'total_paid_amount' => 15000,
            'total_amount' => 20000,
        ]);

        // When: points_first 우선순위로 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => false,
                'refund_priority' => 'points_first',
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // cancelOrder() - 쿠폰 복원 테스트
    // ========================================

    /**
     * 쿠폰이 적용된 주문을 전체취소하면 쿠폰 복원 훅이 호출되고 DB에 반영된다.
     */
    public function test_admin_cancel_restores_coupons_in_db(): void
    {
        // Given: 결제 완료된 주문 + 쿠폰 적용 + 취소/환불 시퀀스
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 30000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
        );
        $order = $data['order'];

        // 쿠폰 발급 레코드 생성 (USED 상태)
        $coupon = \Modules\Sirsoft\Ecommerce\Models\Coupon::create([
            'name' => '전체취소 테스트 쿠폰',
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
            'user_id' => $order->user_id,
            'coupon_code' => 'TEST-CANCEL-001',
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
            'total_coupon_discount_amount' => 3000,
            'total_order_coupon_discount_amount' => 3000,
            'total_paid_amount' => 27000,
            'total_amount' => 30000,
        ]);

        // 쿠폰 복원 훅 리스너 등록 (테스트 환경에서 실제 DB 업데이트 시뮬레이션)
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function ($order, $couponIssueIds) {
            \Modules\Sirsoft\Ecommerce\Models\CouponIssue::whereIn('id', $couponIssueIds)
                ->update([
                    'status' => CouponIssueRecordStatus::AVAILABLE->value,
                    'used_at' => null,
                    'order_id' => null,
                ]);
        }, 10);

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => false,
            ]);

        // Then: 성공 응답 및 쿠폰 상태 AVAILABLE로 복원 확인
        $response->assertOk()
            ->assertJsonPath('success', true);

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status);
    }

    // ========================================
    // 취소 사유 필수 검증 테스트
    // ========================================

    /**
     * @test
     *
     * reason 없이 전체취소 요청 시 422 검증 에러를 반환한다.
     */
    public function cancel_order_requires_reason(): void
    {
        // Given: 결제 완료 주문
        $this->createCancelSequences();
        ['order' => $order] = $this->createPaidOrderWithOptions();

        // When: reason 없이 취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'cancel_pg' => false,
            ]);

        // Then: 422 검증 에러
        $response->assertStatus(422);
    }
}
