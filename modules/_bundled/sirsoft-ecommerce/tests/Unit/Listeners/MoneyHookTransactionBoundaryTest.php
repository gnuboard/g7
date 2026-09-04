<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponAlreadyUsedException;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponUseListener;
use Modules\Sirsoft\Ecommerce\Listeners\MileageTransactionListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 금전 이동 훅의 트랜잭션 경계 회귀 테스트 (KVE-2026-1886 후속)
 *
 * Action 훅은 기본적으로 큐 작업으로 감싸여 `afterCommit` 으로 디스패치된다. 그 기본값을
 * 그대로 두면 쿠폰 차감이 주문 트랜잭션이 **커밋된 뒤에** 실행되어, 차감이 실패해도 주문을
 * 되돌릴 수 없다. 금전 이동 훅은 `sync => true` 로 호출자 트랜잭션 안에서 실행되어야 한다.
 *
 * 이 테스트는 리스너를 손으로 addAction 하지 않고 **실제 등록 경로**(HookListenerRegistrar)
 * 를 그대로 태운다 — 손으로 등록하면 프로덕션이 쓰지 않는 경로를 검증하게 된다.
 */
class MoneyHookTransactionBoundaryTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(CouponUseListener::class, 'test');
    }

    /**
     * 쿠폰 차감은 호출자 트랜잭션 **안에서** 이미 반영되어야 합니다.
     *
     * 커밋 이후로 미뤄지면 이 단언이 실패한다.
     *
     * @return void
     */
    public function test_coupon_deduction_is_visible_inside_caller_transaction(): void
    {
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();
        $observed = null;

        DB::transaction(function () use ($issue, $order, &$observed) {
            HookManager::doAction('sirsoft-ecommerce.coupon.use', [$issue->id], $order);

            $observed = CouponIssue::find($issue->id)->status;
        });

        $this->assertEquals(
            CouponIssueRecordStatus::USED,
            $observed,
            '쿠폰 차감이 호출자 트랜잭션 안에서 반영되어야 합니다 (커밋 이후 실행 금지).'
        );
    }

    /**
     * 선점당한 쿠폰은 호출자 트랜잭션을 롤백시켜야 합니다.
     *
     * 훅이 커밋 뒤에 실행되면 주문 행이 남은 채 예외만 올라온다 — 이 테스트가 그 상태를 잡는다.
     *
     * @return void
     */
    public function test_taken_coupon_rolls_back_caller_transaction(): void
    {
        $winner = $this->createOrder();
        $issue = $this->createAvailableIssue([
            'status' => CouponIssueRecordStatus::USED,
            'used_at' => now(),
            'order_id' => $winner->id,
        ]);

        $ordersBefore = Order::query()->count();
        $orderNumber = 'ORD-BOUNDARY-'.uniqid();
        $thrown = null;

        try {
            DB::transaction(function () use ($issue, $orderNumber) {
                $loser = $this->createOrder($orderNumber);

                HookManager::doAction('sirsoft-ecommerce.coupon.use', [$issue->id], $loser);
            });
        } catch (CouponAlreadyUsedException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '선점된 쿠폰은 예외로 차단되어야 합니다.');
        $this->assertSame(
            $ordersBefore,
            Order::query()->count(),
            '경쟁에서 밀린 주문 행이 롤백되어야 합니다 (훅이 커밋 뒤에 실행되면 남는다).'
        );
        $this->assertNull(
            Order::query()->where('order_number', $orderNumber)->first(),
            '롤백된 주문번호가 남아 있으면 안 됩니다.'
        );
    }

    /**
     * 금전 이동 리스너는 동기 실행을 명시 선언해야 합니다.
     *
     * @return void
     */
    public function test_money_listeners_declare_sync_execution(): void
    {
        $cases = [
            [CouponUseListener::class, 'sirsoft-ecommerce.coupon.use'],
            [CouponRestoreListener::class, 'sirsoft-ecommerce.order.after_cancel'],
            [CouponRestoreListener::class, 'sirsoft-ecommerce.coupon.restore'],
            [MileageTransactionListener::class, 'sirsoft-ecommerce.mileage.use'],
            [MileageTransactionListener::class, 'sirsoft-ecommerce.mileage.restore'],
        ];

        foreach ($cases as [$listener, $hook]) {
            $config = $listener::getSubscribedHooks()[$hook] ?? null;

            $this->assertNotNull($config, "[{$listener}] 가 [{$hook}] 를 구독해야 합니다.");
            $this->assertTrue(
                ! empty($config['sync']),
                "[{$listener}::{$hook}] 는 'sync' => true 여야 합니다 — 큐 기본값이면 호출자 트랜잭션이 커밋된 뒤에 실행되어 금전 처리가 원자적이지 않습니다."
            );
        }
    }

    /**
     * 사용 가능 상태의 쿠폰 발급 레코드를 생성합니다.
     *
     * @param  array  $overrides  오버라이드
     * @return CouponIssue 생성된 발급 레코드
     */
    protected function createAvailableIssue(array $overrides = []): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '경계 점검 쿠폰', 'en' => 'Boundary Probe Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
            'coupon_code' => 'BND'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ], $overrides));
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @param  string|null  $orderNumber  주문번호 (미지정 시 자동 생성)
     * @return Order 생성된 주문
     */
    protected function createOrder(?string $orderNumber = null): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => $orderNumber ?? 'ORD-BND-'.uniqid(),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 49000,
            'total_paid_amount' => 49000,
        ]);
    }
}
