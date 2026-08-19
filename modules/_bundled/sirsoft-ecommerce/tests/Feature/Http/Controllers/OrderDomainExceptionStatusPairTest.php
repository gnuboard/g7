<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 도메인 액션의 예외 → 상태코드 페어 검증 (#104)
 *
 * 배송지 변경(본체)에서 확인된 "인프라 예외가 422 로 위장되는" 결함은 같은 도메인의
 * 다른 액션에도 같은 형태로 존재했다. 여기서는 취소·구매확정·재주문·입금확인·쿠폰
 * 다운로드 각각에 대해 두 축을 실제 HTTP 응답으로 고정한다.
 *
 *  - 도메인 예외(typed) → 기존 4xx 유지 (사용자/운영자 안내)
 *  - 그 외 예외(인프라/코드 결함) → 500, 응답 본문에 예외 원문 미포함
 *
 * 코어 `GenericCatchStatusCodeContractTest` 는 소스 전수로 계약을 고정하지만 "그 계약이 응답까지
 * 도달하는지" 는 증명하지 못한다 — catch 블록에 500 이라고 적혀 있어도 그 앞의
 * FormRequest·미들웨어가 다른 응답을 만들 수 있다. 이 테스트가 그 간극을 메운다.
 */
class OrderDomainExceptionStatusPairTest extends ModuleTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        // 사용자 취소는 PG 환불 훅을 타므로 성공 응답으로 고정 (본 테스트의 관심사가 아님)
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TEST_PAIR_TXN',
            'error_code' => null,
            'error_message' => null,
        ], 10);
    }

    /**
     * 취소 가능한 결제완료 주문(옵션 1개)을 만든다.
     */
    private function makeCancellableOrder(): Order
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
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

        return $order->fresh(['options']);
    }

    /**
     * 사용자 선택 가능한 환불 사유 코드를 반환한다.
     */
    private function refundReasonCode(): string
    {
        $code = ClaimReason::where('type', 'refund')
            ->where('is_active', true)
            ->where('is_user_selectable', true)
            ->value('code');

        if ($code === null) {
            $this->markTestSkipped('사용자 선택 가능한 환불 사유(ClaimReason) 시드가 없다');
        }

        return $code;
    }

    // ------------------------------------------------------------------
    // 회원 주문 취소
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=cancel, error_class=domain
     *
     * @effects order_cancel_domain_exception_returns_422
     */
    public function test_user_cancel_domain_exception_returns_422(): void
    {
        $order = $this->makeCancellableOrder();

        $this->mock(OrderCancellationService::class, function ($mock) {
            $mock->shouldReceive('cancelOrder')
                ->andThrow(new OrderCancellationException('취소 불가'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            ['reason' => $this->refundReasonCode()]
        );

        $response->assertStatus(422);
    }

    /**
     * @scenario endpoint=cancel, error_class=infrastructure
     *
     * @effects order_cancel_infrastructure_exception_returns_500_not_422
     */
    public function test_user_cancel_infrastructure_exception_returns_500(): void
    {
        $order = $this->makeCancellableOrder();

        $this->mock(OrderCancellationService::class, function ($mock) {
            $mock->shouldReceive('cancelOrder')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel",
            ['reason' => $this->refundReasonCode()]
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 회원 구매확정 (typed 예외 없음 — 전부 인프라 축)
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=confirm_option, error_class=infrastructure
     *
     * @effects order_confirm_option_infrastructure_exception_returns_500_not_422
     */
    public function test_user_confirm_option_infrastructure_exception_returns_500(): void
    {
        $order = $this->makeCancellableOrder();
        $option = $order->options->first();
        // 구매확정 가능 상태(기본값 shipping/delivered)여야 FormRequest 를 통과해
        // 컨트롤러의 catch 까지 도달한다.
        $option->update(['option_status' => OrderStatusEnum::DELIVERED]);

        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('confirmOption')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/options/{$option->id}/confirm"
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 회원 재주문
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=reorder, error_class=domain
     *
     * @effects order_reorder_domain_exception_returns_422
     */
    public function test_user_reorder_domain_exception_returns_422(): void
    {
        $order = $this->makeCancellableOrder();

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('reorderFromOrder')
                ->andThrow(new OrderProcessingException('장바구니 담기 불가'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/reorder"
        );

        $response->assertStatus(422);
    }

    /**
     * @scenario endpoint=reorder, error_class=infrastructure
     *
     * @effects order_reorder_infrastructure_exception_returns_500_not_422
     */
    public function test_user_reorder_infrastructure_exception_returns_500(): void
    {
        $order = $this->makeCancellableOrder();

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('reorderFromOrder')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/reorder"
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 회원 쿠폰 다운로드
    // ------------------------------------------------------------------

    /**
     * 다운로드 대상 쿠폰 1건을 만든다 (발급 로직은 mock 이 대체하므로 존재만 하면 된다).
     */
    private function makeDownloadableCoupon(): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '다운로드 쿠폰', 'en' => 'Downloadable Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DOWNLOAD->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 1,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * 발급 불가 사유는 400 을 유지하고, 사유 식별자로 만든 키가 응답 메시지가 된다.
     *
     * @scenario endpoint=coupon_download, error_class=domain
     *
     * @effects coupon_download_domain_exception_returns_400_with_reason_key
     */
    public function test_user_coupon_download_domain_exception_returns_400(): void
    {
        $coupon = $this->makeDownloadableCoupon();

        $this->mock(UserCouponService::class, function ($mock) {
            $mock->shouldReceive('downloadCoupon')
                ->andThrow(new CouponNotIssuableException('already_downloaded'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download"
        );

        $response->assertStatus(400);
        // 번역된 예외 원문이 아니라 사유 키로 해석된 문구가 나가야 한다
        $this->assertSame(
            __('sirsoft-ecommerce::messages.coupon.already_downloaded'),
            $response->json('message')
        );
    }

    /**
     * @scenario endpoint=coupon_download, error_class=infrastructure
     *
     * @effects coupon_download_infrastructure_exception_returns_500_not_400
     */
    public function test_user_coupon_download_infrastructure_exception_returns_500(): void
    {
        $coupon = $this->makeDownloadableCoupon();

        $this->mock(UserCouponService::class, function ($mock) {
            $mock->shouldReceive('downloadCoupon')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->user)->postJson(
            "/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download"
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 관리자 무통장 입금확인
    // ------------------------------------------------------------------

    /**
     * 입금확인 대상(무통장·미결제) 주문을 만든다.
     */
    private function makeDepositPendingOrder(): Order
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'subtotal_amount' => 20000,
            'total_amount' => 20000,
            'total_paid_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'paid_amount_local' => 20000,
            'paid_amount_base' => 20000,
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * @scenario endpoint=confirm_deposit, error_class=domain
     *
     * @effects order_confirm_deposit_domain_exception_returns_422
     */
    public function test_admin_confirm_deposit_domain_exception_returns_422(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->makeDepositPendingOrder();

        $this->mock(OrderProcessingService::class, function ($mock) {
            $mock->shouldReceive('confirmManualDeposit')
                ->andThrow(new OrderProcessingException('전이 불가 상태'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($admin)->patchJson(
            "/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/confirm-deposit",
            ['amount' => 20000]
        );

        $response->assertStatus(422);
    }

    /**
     * @scenario endpoint=confirm_deposit, error_class=infrastructure
     *
     * @effects order_confirm_deposit_infrastructure_exception_returns_500_not_422
     */
    public function test_admin_confirm_deposit_infrastructure_exception_returns_500(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->makeDepositPendingOrder();

        $this->mock(OrderProcessingService::class, function ($mock) {
            $mock->shouldReceive('confirmManualDeposit')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($admin)->patchJson(
            "/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/confirm-deposit",
            ['amount' => 20000]
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
