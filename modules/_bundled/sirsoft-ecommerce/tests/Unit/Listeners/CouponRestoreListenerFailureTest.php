<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 복원 리스너 — 실패를 삼키지 않는다 (F1·D2)
 *
 * 복원이 실패하면 취소·환불 전체가 롤백돼야 한다. 리스너가 먼저 삼키면 서비스가 아무리 던져도
 * 실패가 올라오지 않아, 환불은 끝났는데 쿠폰은 사용됨으로 남는다.
 */
class CouponRestoreListenerFailureTest extends ModuleTestCase
{
    /**
     * 사용됨 상태의 쿠폰 발급 레코드를 만듭니다.
     *
     * @return CouponIssue 사용된 쿠폰
     */
    protected function usedCouponIssue(): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
            'used_at' => now()->subHour(),
        ]);
    }

    /**
     * 그 쿠폰을 쓴 취소 주문을 만듭니다. (실데이터 스냅샷 키 형식)
     *
     * @param  CouponIssue  $issue  쿠폰 발급 레코드
     * @return Order 취소 주문
     */
    protected function orderUsing(CouponIssue $issue): Order
    {
        return Order::create([
            'user_id' => $issue->user_id,
            'order_number' => 'ORD-TEST-'.uniqid(),
            'order_status' => OrderStatusEnum::CANCELLED,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 45000,
            'total_paid_amount' => 45000,
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => [$issue->id],
                'item_coupons' => [],
                'discount_code' => null,
                'product_promotions' => ['coupons' => [[
                    'coupon_id' => $issue->coupon_id,
                    'coupon_issue_id' => $issue->id,
                    'name' => '테스트 쿠폰',
                    'target_type' => 'product_amount',
                    'discount_type' => 'fixed',
                    'discount_value' => 5000,
                    'total_discount' => 5000,
                ]]],
                'order_promotions' => ['coupons' => []],
            ],
        ]);
    }

    /**
     * 저장소가 쓰기에서 던지는 리스너를 만듭니다. (USED 쿠폰을 찾은 뒤 갱신이 실패하는 상황)
     *
     * @param  CouponIssue  $issue  복원 대상 쿠폰
     * @return CouponRestoreListener 실패하는 저장소를 주입한 리스너
     */
    protected function failingListener(CouponIssue $issue): CouponRestoreListener
    {
        $repository = \Mockery::mock(CouponIssueRepositoryInterface::class);
        $repository->shouldReceive('findById')->andReturn($issue);
        $repository->shouldReceive('updateIfStatus')->andThrow(new \RuntimeException('잠금 대기 초과'));

        return new CouponRestoreListener($repository);
    }

    /**
     * 명시 ID 복원(부분취소·환불 실행)이 실패하면 삼키지 않고 환불 중단 예외로 다시 던진다.
     *
     * @effects coupon_restore_by_ids_failure_is_rethrown
     *
     * @return void 반환값 없음
     */
    public function test_restore_by_ids_failure_is_rethrown(): void
    {
        $issue = $this->usedCouponIssue();
        $order = $this->orderUsing($issue);

        try {
            $this->failingListener($issue)->restoreCouponsByIds($order, [$issue->id]);
            $this->fail('쿠폰 복원 실패가 올라와야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.coupon_restore_failed', $e->getMessageKey());
        }
    }

    /**
     * 스냅샷 기반 복원(주문 취소 훅)이 실패해도 삼키지 않고 다시 던진다.
     *
     * @effects coupon_restore_from_snapshot_failure_is_rethrown
     *
     * @return void 반환값 없음
     */
    public function test_restore_from_snapshot_failure_is_rethrown(): void
    {
        $issue = $this->usedCouponIssue();
        $order = $this->orderUsing($issue);

        try {
            $this->failingListener($issue)->restoreCoupons($order);
            $this->fail('쿠폰 복원 실패가 올라와야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.coupon_restore_failed', $e->getMessageKey());
        }
    }
}
