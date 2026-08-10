<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 직접발급 (A16③)
 *
 * 관리자가 회원을 지정해 즉시 발급. per-item 발급/skip, DR- 코드, 활동로그, downloadCoupon 회귀.
 */
class CouponDirectIssueTest extends ModuleTestCase
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
     * 발급 가능한 쿠폰 1건 생성
     */
    private function createIssuableCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '직접발급 쿠폰', 'en' => 'Direct Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 1,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $this->adminUser->id,
        ], $overrides));
    }

    private function endpoint(int $couponId): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}/issue-direct";
    }

    /**
     * #1 회원 2명 지정, 둘 다 issuable → CouponIssue 2건 + issued_count +2 + DR- + 활동로그 2건
     */
    public function test_direct_issue_to_two_users_creates_two_records(): void
    {
        $coupon = $this->createIssuableCoupon(['per_user_limit' => 0]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->endpoint($coupon->id), [
                'user_uuids' => [$user1->uuid, $user2->uuid],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.issued'));
        $this->assertCount(0, $response->json('data.skipped'));

        $issues = CouponIssue::where('coupon_id', $coupon->id)->get();
        $this->assertCount(2, $issues);
        foreach ($issues as $issue) {
            $this->assertStringStartsWith('DR-', $issue->coupon_code);
        }

        $this->assertEquals(2, $coupon->fresh()->issued_count);

        // 활동로그 2건 (per-item)
        $this->assertEquals(2, \DB::table('activity_logs')
            ->where('action', 'coupon.direct_issue')
            ->where('loggable_type', CouponIssue::class)
            ->count());
    }

    /**
     * #2 1명 per_user_limit 도달 → 1건 발급 + skip 사유 1건 (전체 롤백 아님)
     */
    public function test_direct_issue_skips_user_at_limit(): void
    {
        $coupon = $this->createIssuableCoupon(['per_user_limit' => 1]);
        $reached = User::factory()->create();
        $fresh = User::factory()->create();

        // reached 회원은 이미 1건 발급(한도 도달) — 서비스로 직접 발급
        app(UserCouponService::class)->issueDirectlyToUser($coupon->fresh(), $reached->id);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->endpoint($coupon->id), [
                'user_uuids' => [$reached->uuid, $fresh->uuid],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.issued'));
        $this->assertCount(1, $response->json('data.skipped'));
        $this->assertEquals($reached->id, $response->json('data.skipped.0.user_id'));

        // fresh 회원만 이번 요청으로 발급됨
        $this->assertTrue(CouponIssue::where('coupon_id', $coupon->id)->where('user_id', $fresh->id)->exists());
    }

    /**
     * #3 user_uuids=[] → 422 (IssueCouponDirectRequest min:1)
     */
    public function test_direct_issue_empty_user_ids_is_rejected(): void
    {
        $coupon = $this->createIssuableCoupon();

        $this->actingAs($this->adminUser)
            ->postJson($this->endpoint($coupon->id), ['user_uuids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_uuids');
    }

    /**
     * #4 코어 회귀: downloadCoupon() 추출 후에도 DL- 코드·동일 동작
     */
    public function test_download_coupon_still_uses_dl_prefix(): void
    {
        $coupon = $this->createIssuableCoupon(['per_user_limit' => 0]);
        $user = User::factory()->create();

        $issue = app(UserCouponService::class)->downloadCoupon($user->id, $coupon->id);

        $this->assertStringStartsWith('DL-', $issue->coupon_code);
        $this->assertEquals($coupon->id, $issue->coupon_id);
        $this->assertEquals($user->id, $issue->user_id);
        $this->assertEquals(1, $coupon->fresh()->issued_count);
    }
}
