<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

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

    /**
     * 주문 수정 권한 운영자를 세우고, 남은 환경설정 파일을 비우고, PG 환불 리스너를 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 예상금액 조회는 접수(claims.create) 축, 「환불까지 한 번에」 는 환불 실행 권한이 필요하다 (7.1.0).
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.orders.update',
            'sirsoft-ecommerce.claims.create',
            'sirsoft-ecommerce.claims.execute-refund',
        ]);

        // 이전 테스트에서 저장된 환경설정 파일 제거 (RefreshDatabase 는 storage 롤백 안 함)
        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (is_dir($settingsDir)) {
            foreach (glob($settingsDir.'/*.json') as $file) {
                @unlink($file);
            }
        }
        app(EcommerceSettingsService::class)->clearCache();

        // g7_settings.modules Config cache 도 제거 (CoreServiceProvider 가 boot 시 로드)
        Config::set('g7_settings.modules.sirsoft-ecommerce', []);

        // PG 환불 리스너 — 관리자 취소는 환불신청서를 만들고, 자동 전이를 켜면 그 환불이
        // 실제로 집행된다. 구독자가 하나도 없으면 코어가 "결제 플러그인 없음" 으로 보고 계좌
        // 환불로 폴백하므로(P14), PG 경로를 검사하려면 리스너가 있어야 한다. 개별 테스트가
        // 자기 리스너를 우선순위로 덮어쓴다.
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TEST_TXN_DEFAULT',
            'error_code' => null,
            'error_message' => null,
        ], 50);
    }

    /**
     * 취소/환불 시퀀스를 생성합니다.
     *
     * TestingSeeder는 ORDER 시퀀스만 생성하므로,
     * 취소/환불 번호 채번을 위해 CANCEL과 REFUND 시퀀스를 추가합니다.
     *
     * @return void 반환값 없음
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
     * @param  array  $paymentOverrides  결제행 덮어쓰기 속성 (결제수단 등)
     * @return array{order: Order, options: array<OrderOption>, payment: ?OrderPayment, shipping: ?OrderShipping} 주문·주문옵션·결제행·배송행 묶음
     */
    private function createPaidOrderWithOptions(
        int $optionCount = 1,
        int $unitPrice = 20000,
        int $quantity = 1,
        bool $withPayment = false,
        bool $withShipping = false,
        array $paymentOverrides = [],
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
            $payment = OrderPayment::factory()->forOrder($order)->create(array_merge([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_amount_local' => $totalAmount,
                'paid_amount_base' => $totalAmount,
                'paid_at' => now(),
            ], $paymentOverrides));
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
     * 관리자가 유효한 취소 아이템으로 환불 예상금액을 조회할 수 있다 — 응답에 환불 예상액이 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=multi_subset_full, payment_state=card_paid
     *
     * @effects admin_estimate_refund_returns_the_refund_amount
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
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_estimate_refund_requires_items
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
     * 자동 전이를 켜고 전체취소하면 주문 상태가 CANCELLED 로 바뀐다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_auto_advance_full_cancel_cancels_the_order
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

        // When: 자동 전이를 켜고 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
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
     * 자동 전이를 켜고 부분취소하면 지정한 옵션만 대상으로 접수가 성공한다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=multi_subset_full, payment_state=card_paid
     *
     * @effects admin_auto_advance_partial_cancel_is_accepted
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
                'auto_advance_refund' => true,
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // cancelOrder() 테스트 - PG 연동
    // ========================================

    /**
     * 자동 전이를 켜면 PG 환불 훅이 성공을 돌려주는 주문의 취소가 한 요청으로 끝난다. (#599 후속 D7)
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_auto_advance_cancel_is_accepted_in_one_request
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
                'transaction_id' => 'TEST_TXN_'.time(),
                'error_code' => null,
                'error_message' => null,
            ];
        }, 10);

        // When: 자동 전이를 켜고 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        // Then: 성공 응답 (PG 환불 훅 성공)
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * 확장 결제수단(간편결제) 주문을 취소하면 PG 환불 훅이 실제로 진입해야 한다 (#475).
     *
     * 취소 서비스는 `$order->payment->needsPgProvider()` 가 true 일 때만 PG 환불
     * 훅(`payment.refund`)에 진입한다(OrderCancellationService:279-281). 확장 ID 는
     * enum case 가 없어 과거에는 이 게이트에서 false 로 떨어져 카드 취소가 누락됐다.
     * 이 테스트는 게이트 판정값이 아니라 실제 훅 발화를 스파이로 관측한다 — 판정과
     * 훅 진입 사이 로직이 바뀌어 환불 누락이 재발하면 이 테스트가 잡는다.
     *
     * @return void 반환값 없음
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=refund_method
     *
     * @effects refund_method_is_pg, pg_refund_hook_invoked
     */
    #[Test]
    public function admin_cancel_extension_payment_order_enters_pg_refund_hook(): void
    {
        $this->createCancelSequences();

        // PG 플러그인이 하는 것과 동일하게 간편결제 수단을 카탈로그에 등록해
        // needsPgProvider('nhnkcp_naverpay') 가 카탈로그 능력으로 true 가 되게 한다.
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => 'nhnkcp_naverpay',
                'name' => ['ko' => '네이버페이', 'en' => 'Naver Pay'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:sirsoft-pay_nhnkcp',
                'defaults' => [
                    'pg_provider' => 'nhnkcp',
                    'pg_locked' => true,
                    'needs_pg' => true,
                    'refund_method' => 'pg',
                    // 간편결제는 PG 결제취소 API 가 그 자리에서 환불하므로 자동 승인 대상이다
                    // (P16·P17). 번들 PG 플러그인 4종의 등록 리스너가 실제로 선언하는 값이며,
                    // 선언하지 않으면 그 수단의 환불은 운영자 승인 대기로 멈춘다 — 돈이 새지는
                    // 않지만 자동 전이가 없어 아래 PG 훅 진입 단언이 성립하지 않는다.
                    'auto_approve_refund' => true,
                    'is_active' => true,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                    'mileage_deduction_timing' => 'payment_complete',
                ],
            ]])
        );
        // 설정 캐시도 함께 비운다 — 카탈로그는 `order_settings.payment_methods` 조회 결과이고
        // 그 조회는 서비스가 캐시한다. 리졸버만 비우면 훅 등록 전의 카탈로그를 다시 읽어
        // 이 수단이 카탈로그에 없는 상태가 된다(능력 질의가 폴백으로만 답한다).
        app(EcommerceSettingsService::class)->clearCache();
        app(PaymentMethodResolver::class)->flushCache();

        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 30000,
            quantity: 1,
            withPayment: true,
        );
        $order = $data['order'];
        // 결제 레코드를 확장 결제수단으로 지정 (기본 factory 는 card).
        $order->payment()->update([
            'payment_method' => 'nhnkcp_naverpay',
            'pg_provider' => 'nhnkcp',
        ]);

        // PG 환불 훅 진입 여부를 관측하는 스파이 (성공 응답도 시뮬레이션).
        $refundHook = new \ArrayObject(['count' => 0]);
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use ($refundHook) {
            $refundHook['count']++;

            return [
                'success' => true,
                'transaction_id' => 'TEST_TXN_EXT',
                'error_code' => null,
                'error_message' => null,
            ];
        }, 10);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(
            1,
            $refundHook['count'],
            '확장 결제수단(간편결제) 취소 시 PG 환불 훅이 진입해야 한다 '
            .'(미진입 시 카드 취소가 누락되는 #475 안전 결함 재발)'
        );
    }

    /**
     * 자동 전이를 켜면 취소가 한 요청으로 끝난다 — 개별 PG 리스너 없이 기본 리스너만으로도 성공한다. (#599 후속 D7)
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_auto_advance_cancel_is_accepted_in_one_request
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

        // When: 자동 전이를 켜고 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'admin_cancel',
                'auto_advance_refund' => true,
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // cancelOrder() 테스트 - 에러 케이스
    // ========================================

    /**
     * 배송 중(SHIPPING) 주문의 취소 요청은 422 로 거절된다. (상품 단위 물리 게이트는 D5 테스트가 따로 잰다)
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_of_a_shipping_order_is_rejected
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
     * 배송 중(SHIPPING) 주문의 취소 요청은 422 와 success=false 봉투로 거절된다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_of_a_shipping_order_is_rejected
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
     * 결제된 단일 상품 주문을 주문·상품 상태가 모두 주어진 상태인 채로 만듭니다. (취소 게이트 판정용)
     *
     * @param  OrderStatusEnum  $status  주문·주문상품 상태
     * @return Order 주문 (상품 1행 · 결제완료 결제행 포함)
     */
    private function paidOrderIn(OrderStatusEnum $status): Order
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => $status,
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
            'option_status' => $status,
        ]);
        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 20000,
            'paid_amount_base' => 20000,
            'paid_at' => now(),
        ]);

        return $order;
    }

    /**
     * 운영자 취소는 설정의 취소 가능 상태(`order_settings.cancellable_statuses`)를 따르지 않는다 — 설정이
     * 결제완료만 허용해도 아직 보내지 않은 상품준비중 주문은 취소된다.
     *
     * 계획 결정 D5(물리 게이트): 취소 가능 상태 설정은 **고객 축**이다. 운영자의 취소·환불 접수는
     * 물건이 아직 나가지 않았는지만 본다(`ClaimPhysicalGate` — `AdminClaimRequestService::requestCancelOrRefund()`).
     * 종전에는 운영자 취소도 이 설정을 따라서, 설정 밖이지만 보내지 않은 배송보류·상품준비중 주문을 운영자가
     * 취소할 길이 없었다. 고객 쪽 설정 게이트는 `User\OrderCancellationControllerTest` 가 그대로 잰다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_ignores_the_cancellable_status_setting_for_unshipped_items
     */
    public function test_admin_cancel_ignores_the_cancellable_status_setting_for_unshipped_items(): void
    {
        // Given: 설정은 결제완료만 취소 가능 — 상품준비중은 설정 밖이지만 아직 보내지 않았다
        $this->createCancelSequences();
        app(EcommerceSettingsService::class)->setSetting('order_settings.cancellable_statuses', ['payment_complete']);
        $order = $this->paidOrderIn(OrderStatusEnum::PREPARING);

        // When: 자동 전이를 켜고 전체취소
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        // Then: 성공 + 주문 CANCELLED — 운영자 신청서(출처 admin)가 남는다
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.claim.cancelled', true);

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $this->assertSame(
            RefundSourceTypeEnum::ADMIN,
            OrderRefund::query()->where('order_id', $order->id)->latest('id')->first()?->refund_source_type,
        );
    }

    /**
     * 이미 보낸(배송중) 주문은 설정이 그 상태를 취소 가능으로 적어 두어도 운영자가 취소할 수 없다 — 422 이고
     * 신청서도 남지 않는다. 물건이 고객에게 가는 중이면 취소가 아니라 반품의 자리다(D5 물리 게이트).
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_refuses_shipped_items_even_when_the_setting_allows
     */
    public function test_admin_cancel_refuses_shipped_items_even_when_the_setting_allows(): void
    {
        // Given: 설정이 배송중까지 취소 가능으로 적혀 있어도(고객 축) 물건은 이미 나갔다
        $this->createCancelSequences();
        app(EcommerceSettingsService::class)->setSetting(
            'order_settings.cancellable_statuses',
            ['payment_complete', 'preparing', 'shipping'],
        );
        $order = $this->paidOrderIn(OrderStatusEnum::SHIPPING);

        // When: 전체취소
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        // Then: 422 · 주문 상태 그대로 · 신청서 없음
        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertEquals(OrderStatusEnum::SHIPPING, $order->fresh()->order_status);
        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 이미 취소된 주문을 다시 취소 요청하면 422 로 거절된다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_of_a_cancelled_order_is_rejected
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
     * 존재하지 않는 주문번호로 취소 요청하면 404 를 반환한다.
     *
     * @return void 반환값 없음
     *
     * @effects admin_cancel_of_a_missing_order_is_404
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
     * refund_priority=pg_first 로 환불 예상금액을 조회하면 응답이 그 우선순위를 그대로 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=points_used
     *
     * @effects cancel_preview_carries_the_requested_refund_priority
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
     * refund_priority=points_first 로 환불 예상금액을 조회하면 응답이 그 우선순위를 그대로 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=points_used
     *
     * @effects cancel_preview_carries_the_requested_refund_priority
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
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_estimate_refund_carries_shipping_details
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
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_estimate_refund_carries_restored_coupons
     */
    public function test_admin_estimate_refund_includes_restored_coupons(): void
    {
        // Given: 결제 완료된 주문 + 쿠폰 적용 스냅샷
        $data = $this->createPaidOrderWithOptions(optionCount: 1, unitPrice: 30000, quantity: 1, withPayment: true);
        $order = $data['order'];
        $options = $data['options'];

        // 쿠폰 발급 레코드 생성
        $coupon = Coupon::create([
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

        $couponIssue = CouponIssue::create([
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
     * 포인트 사용 주문의 환불 예상금액 응답에 남은 PG·포인트 잔액 키가 포함된다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=multi_subset_full, payment_state=points_used
     *
     * @effects cancel_reports_the_remaining_pg_and_point_balances
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
     * 환불 우선순위(points_first)를 지정한 자동 전이 전체취소가 성공한다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=points_used
     *
     * @effects admin_cancel_accepts_a_refund_priority
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
                'auto_advance_refund' => true,
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
     * 쿠폰이 적용된 주문을 전체취소하면 쿠폰 복원 훅이 호출되어 발급 쿠폰이 사용 가능 상태로 돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_restores_the_used_coupon
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
        $coupon = Coupon::create([
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

        $couponIssue = CouponIssue::create([
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

        // 가짜 리스너 없이 모듈이 등록한 실제 CouponRestoreListener 가 복원한다(T11)

        // When: 전체취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        // Then: 성공 응답 및 쿠폰 상태 AVAILABLE로 복원 확인
        $response->assertOk()
            ->assertJsonPath('success', true);

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status);
        $this->assertNull($couponIssue->used_at);
    }

    // ========================================
    // 취소 사유 필수 검증 테스트
    // ========================================

    /**
     * 취소 사유(reason) 없이 전체취소를 요청하면 422 검증 에러를 반환한다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects admin_cancel_requires_a_reason
     */
    #[Test]
    public function cancel_order_requires_reason(): void
    {
        // Given: 결제 완료 주문
        $this->createCancelSequences();
        ['order' => $order] = $this->createPaidOrderWithOptions();

        // When: reason 없이 취소 요청
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'auto_advance_refund' => true,
            ]);

        // Then: 422 검증 에러
        $response->assertStatus(422);
    }

    /**
     * 자동 전이를 끄면 **환불신청서만 만들고 주문은 아직 취소되지 않는다**. (#599 후속 D7)
     *
     * 관리자 [주문취소]는 환불신청서를 만들 뿐이고, 금액확인 → 승인 → 환불완료는 운영자가
     * 하나씩 밟는 것이 기본 운영이다. 취소신청서는 그 환불이 완료되는 순간에 생기므로 이
     * 시점의 주문은 아직 취소로 확정돼 있지 않다.
     *
     * 이 테스트는 `[case:backend-44]` 의 회귀 그물도 겸한다 — 컨트롤러가 리소스를
     * `array_merge` 로 펼 때 `resolve()` 대신 `toArray()` 를 부르면 미로드 관계가
     * `MissingValue` 로 남아 이 응답만 500 이 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects frontend_auto_advance_defaults_to_off
     */
    public function test_admin_cancel_only_files_a_refund_request_by_default(): void
    {
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
        );
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            // 접수 결과 봉투 — 화면이 "취소됐다" 와 "신청서만 생겼다" 를 가르는 축이다.
            //
            // 이 세 단언이 곧 `OrderResource` 직렬화 회귀의 그물이기도 하다. 컨트롤러는
            // 리소스를 `array_merge` 로 펴서 봉투를 얹는데, `toArray()` 를 직접 부르면
            // 로드되지 않은 관계가 `MissingValue` 로 남아 중첩 리소스가 응답 직렬화 단계에서
            // 500 이 된다(트러블슈팅 backend-37). 그 필터는 `resolve()` 안에 있다.
            ->assertJsonPath('data.claim.branch', 'request')
            ->assertJsonPath('data.claim.cancelled', false)
            ->assertJsonStructure(['data' => ['claim' => ['claim_number']]]);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund, '관리자 취소는 환불신청서를 만든다');
        $this->assertEquals(
            RefundSourceTypeEnum::ADMIN,
            $refund->refund_source_type,
            '운영자 대리 접수라야 고객이 철회할 수 없다',
        );
        $this->assertEquals(
            RefundStatusEnum::REQUESTED,
            $refund->refund_status,
            '자동 전이를 끄면 접수에서 멈춘다',
        );

        $order->refresh();
        $this->assertNotEquals(
            OrderStatusEnum::CANCELLED,
            $order->order_status,
            '환불이 완료되기 전에는 주문이 취소로 확정되지 않는다',
        );
        $this->assertSame(
            0,
            OrderCancel::where('order_id', $order->id)->count(),
            '취소신청서는 환불완료 순간에 생긴다 (D3)',
        );
    }

    /**
     * `auto_advance_refund` 를 켜도 **정지 수단 주문은 접수에서 멈춘다**. (P16)
     *
     * 체크박스는 그대로 두되(운영자가 카드·무통장을 가리지 않고 같은 모달을 쓴다) 결제수단이
     * 목록 밖이면 전이하지 않는다. 술어를 호출부가 아니라 `willAutoAdvance()` 안에 둔 것이
     * 이 축의 근거다 — 관리자 경로는 따로 게이트를 걸지 않았는데도 같은 술어를 지나므로 막힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects stopped_payment_method_ignores_admin_auto_advance
     */
    public function test_admin_auto_advance_stops_at_requested_for_a_bank_transfer_order(): void
    {
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
            paymentOverrides: ['payment_method' => 'dbank'],
        );
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
                // 계좌 환불 수단은 환불받을 계좌가 필수다 — 게이트 축과 별개의 기존 검증.
                'refund_bank' => ['bank_code' => '004', 'account_number' => '1234567890', 'holder' => '홍길동'],
            ])
            ->assertOk()
            ->assertJsonPath('data.claim.cancelled', false);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(
            RefundStatusEnum::REQUESTED,
            $refund->refund_status,
            '무통장은 자동 전이 체크를 해도 접수에서 멈춘다 (P16)',
        );
        $this->assertSame(
            0,
            OrderCancel::where('order_id', $order->id)->count(),
            '취소신청서는 환불완료 순간에 생긴다 (D3)',
        );
    }

    /**
     * 자동 승인 목록에 있는 수단(카드)은 `auto_advance_refund` 로 완료까지 간다. (P16 비회귀)
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=allowed_method_admin_auto_advance
     *
     * @effects allowed_payment_method_still_auto_completes
     */
    public function test_admin_auto_advance_completes_for_a_card_order(): void
    {
        $this->createCancelSequences();
        $data = $this->createPaidOrderWithOptions(
            optionCount: 1,
            unitPrice: 20000,
            quantity: 1,
            withPayment: true,
            withShipping: true,
            paymentOverrides: ['payment_method' => 'card'],
        );
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ])
            ->assertOk();

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(
            RefundStatusEnum::COMPLETED,
            $refund->refund_status,
            '카드는 PG 가 그 자리에서 환불하므로 완료까지 간다',
        );
    }
}
