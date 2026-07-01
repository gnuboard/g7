<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 발급취소 (관리자가 미사용 발급 건을 취소)
 *
 * 미사용(available) 건만 취소 가능, cancelled 전환 + issued_count 복원 + 활동로그.
 * 사용/만료/취소 건 차단, 쿠폰ID 불일치 차단 경계 케이스 전수.
 */
class CouponIssueCancelTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 발급 가능한 쿠폰 1건 생성 (issued_count 초기값 지정 가능)
     */
    private function createCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '발급취소 쿠폰', 'en' => 'Cancel Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
            'issued_count' => 1,
            'created_by' => $this->adminUser->id,
        ], $overrides));
    }

    /**
     * 발급 내역 1건 생성
     */
    private function createIssue(Coupon $coupon, User $user, string $status = 'available', array $overrides = []): CouponIssue
    {
        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'DR-TEST0001',
            'status' => $status,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ], $overrides));
    }

    private function endpoint(int $couponId, int $issueId): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}/issues/{$issueId}";
    }

    /**
     * #1 미사용(available) 발급 건 취소 → cancelled 전환 + issued_count -1 + 활동로그 1건
     */
    public function test_cancel_available_issue_marks_cancelled_and_decrements_count(): void
    {
        $coupon = $this->createCoupon(['issued_count' => 1]);
        $user = User::factory()->create();
        $issue = $this->createIssue($coupon, $user, 'available');

        $response = $this->actingAs($this->adminUser)
            ->deleteJson($this->endpoint($coupon->id, $issue->id));

        $response->assertStatus(200);

        $this->assertEquals(
            CouponIssueRecordStatus::CANCELLED,
            $issue->fresh()->status
        );
        $this->assertEquals(0, $coupon->fresh()->issued_count);

        $this->assertEquals(1, \DB::table('activity_logs')
            ->where('action', 'coupon.issue_cancel')
            ->where('loggable_type', CouponIssue::class)
            ->where('loggable_id', $issue->id)
            ->count());
    }

    /**
     * #2 사용완료(used) 건 취소 시도 → 400 차단, 상태 불변, issued_count 불변
     */
    public function test_cancel_used_issue_is_rejected(): void
    {
        $coupon = $this->createCoupon(['issued_count' => 1]);
        $user = User::factory()->create();
        $issue = $this->createIssue($coupon, $user, 'used', ['used_at' => now()]);

        $this->actingAs($this->adminUser)
            ->deleteJson($this->endpoint($coupon->id, $issue->id))
            ->assertStatus(400);

        $this->assertEquals(CouponIssueRecordStatus::USED, $issue->fresh()->status);
        $this->assertEquals(1, $coupon->fresh()->issued_count);
    }

    /**
     * #3 이미 취소(cancelled)된 건 재취소 시도 → 400 차단 (중복 차감 방지)
     */
    public function test_cancel_already_cancelled_issue_is_rejected(): void
    {
        $coupon = $this->createCoupon(['issued_count' => 0]);
        $user = User::factory()->create();
        $issue = $this->createIssue($coupon, $user, 'cancelled');

        $this->actingAs($this->adminUser)
            ->deleteJson($this->endpoint($coupon->id, $issue->id))
            ->assertStatus(400);

        $this->assertEquals(0, $coupon->fresh()->issued_count);
    }

    /**
     * #4 만료(expired) 건 취소 시도 → 400 차단 (available 외 전부 차단)
     */
    public function test_cancel_expired_issue_is_rejected(): void
    {
        $coupon = $this->createCoupon(['issued_count' => 1]);
        $user = User::factory()->create();
        $issue = $this->createIssue($coupon, $user, 'expired');

        $this->actingAs($this->adminUser)
            ->deleteJson($this->endpoint($coupon->id, $issue->id))
            ->assertStatus(400);

        $this->assertEquals(CouponIssueRecordStatus::EXPIRED, $issue->fresh()->status);
    }

    /**
     * #5 쿠폰ID 불일치 (다른 쿠폰의 발급 ID) → 400 차단 (URL 정합성 검증)
     */
    public function test_cancel_issue_with_mismatched_coupon_id_is_rejected(): void
    {
        $couponA = $this->createCoupon(['issued_count' => 1]);
        $couponB = $this->createCoupon(['issued_count' => 1]);
        $user = User::factory()->create();
        $issueOfB = $this->createIssue($couponB, $user, 'available');

        // couponA URL 로 couponB 의 발급 건 취소 시도
        $this->actingAs($this->adminUser)
            ->deleteJson($this->endpoint($couponA->id, $issueOfB->id))
            ->assertStatus(400);

        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $issueOfB->fresh()->status);
    }

    /**
     * #6 권한 없는 사용자(update 권한 없음) → 403
     */
    public function test_cancel_issue_requires_update_permission(): void
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();
        $issue = $this->createIssue($coupon, $user, 'available');

        $readOnlyAdmin = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);

        $this->actingAs($readOnlyAdmin)
            ->deleteJson($this->endpoint($coupon->id, $issue->id))
            ->assertStatus(403);
    }
}
