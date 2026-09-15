<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 다운로드 게이트 회귀 테스트 (④⑤)
 *
 * downloadCoupon 경로는 getDownloadableCoupons 목록 조건과 대칭인 게이트를 거쳐야 한다.
 * 발급 방법(다운로드)/발급 조건(수동)이 아니거나 유효기간(valid_to)이 만료된 쿠폰의 id 를
 * 직접 지정해 다운로드하는 것을 차단하며, 거부 시 발급 카운터가 소모되지 않아야 한다.
 */
class CouponDownloadGuardTest extends ModuleTestCase
{
    /**
     * 다운로드 가능 쿠폰 기본형을 생성합니다.
     *
     * @param  array<string, mixed>  $overrides  오버라이드 속성
     * @return Coupon
     */
    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '설명', 'en' => 'Desc'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 10000,
            'target_scope' => CouponTargetScope::ALL,
            'issue_method' => CouponIssueMethod::DOWNLOAD,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'issue_from' => Carbon::now()->subDay(),
            'issue_to' => Carbon::now()->addMonth(),
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
            'valid_type' => 'period',
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    /**
     * 자동발급 전용 쿠폰(issue_method=AUTO)은 id 직접 다운로드가 거부되고 카운터가 소모되지 않는다.
     *
     * @scenario case=coupon_method_gate
     *
     * @effects non_downloadable_coupon_rejected
     */
    public function test_auto_issue_coupon_cannot_be_downloaded_by_id(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon(['issue_method' => CouponIssueMethod::AUTO]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        $response->assertStatus(400);

        // per_user_limit / issued_count 미소모 + 발급 레코드 미생성
        $this->assertSame(0, $coupon->fresh()->issued_count);
        $this->assertDatabaseMissing('ecommerce_promotion_coupon_issues', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * 관리자 직접발급 조건(issue_condition != MANUAL)인 쿠폰도 다운로드가 거부된다.
     *
     * @scenario case=coupon_condition_gate
     *
     * @effects non_downloadable_coupon_rejected
     */
    public function test_non_manual_condition_coupon_cannot_be_downloaded(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon(['issue_condition' => CouponIssueCondition::SIGNUP]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        $response->assertStatus(400);

        $this->assertSame(0, $coupon->fresh()->issued_count);
        $this->assertDatabaseMissing('ecommerce_promotion_coupon_issues', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * 유효기간(valid_to)이 만료된 쿠폰은 다운로드가 거부되고 카운터가 소모되지 않는다.
     *
     * @scenario case=coupon_expired_gate
     *
     * @effects expired_coupon_rejected
     */
    public function test_expired_valid_to_coupon_cannot_be_downloaded(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon([
            'valid_from' => Carbon::now()->subMonth(),
            'valid_to' => Carbon::now()->subDay(), // 유효기간 만료
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        $response->assertStatus(400);

        // 만료 거부 시에도 발급 카운터 미소모
        $this->assertSame(0, $coupon->fresh()->issued_count);
        $this->assertDatabaseMissing('ecommerce_promotion_coupon_issues', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * 정상적인 다운로드 전용 쿠폰은 여전히 다운로드된다 (게이트 과잉 차단 회귀 방지).
     *
     * @scenario case=coupon_valid_download
     *
     * @effects valid_manual_download_coupon_succeeds
     */
    public function test_valid_downloadable_coupon_still_succeeds(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        $response->assertStatus(201);
        $this->assertSame(1, $coupon->fresh()->issued_count);
    }
}
