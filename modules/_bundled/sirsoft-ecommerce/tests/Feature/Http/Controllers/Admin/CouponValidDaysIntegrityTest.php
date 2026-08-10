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
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 유효기간 정합성 — valid_type=days_from_issue 인데 valid_days 가 비어 있는 상태
 *
 * 생성 요청(StoreCouponRequest)에는 required_if 가 있으나 수정 요청에는 없어,
 * 수정 경로로 (days_from_issue, valid_days=null) 조합을 저장할 수 있었습니다.
 *
 * Carbon 은 NULL 을 0 일로 흡수하므로 이 조합은 예외를 던지지 않습니다(실측: `addDays(null)`
 * 은 무변화, TypeError 는 숫자 문자열에서만 발생). 대신 만료일이 발급 시각과 같아져
 * **발급 즉시 만료된 쿠폰이 조용히 발급**됩니다 — 예외도 로그도 없는 오작동입니다.
 *
 * 그래서 ① 수정 요청에서 확정될 조합을 검증해 저장을 막고, ② 이미 저장된 데이터는
 * 발급 관문(assertIssuable)에서 명시적으로 차단합니다.
 *
 * @effects coupon_update_rejects_days_from_issue_without_valid_days, coupon_issue_blocked_when_validity_not_configured
 */
class CouponValidDaysIntegrityTest extends ModuleTestCase
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
     * 검증을 우회해 쿠폰 행을 직접 만듭니다. (기존에 저장돼 있던 데이터 재현용)
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return Coupon 생성된 쿠폰
     */
    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
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
        ], $overrides));
    }

    /**
     * 수정 요청 본문 기본값 (부분 수정이므로 필수 필드만)
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'per_user_limit' => 0,
        ], $overrides);
    }

    /**
     * 수정: valid_type=days_from_issue 로 바꾸면서 valid_days 를 비우면 거부된다.
     *
     * @effects coupon_update_rejects_days_from_issue_without_valid_days
     */
    public function test_update_rejects_days_from_issue_with_null_valid_days(): void
    {
        $coupon = $this->makeCoupon([
            'valid_type' => 'period',
            'valid_days' => null,
            'valid_from' => now(),
            'valid_to' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}", $this->updatePayload([
                'valid_type' => 'days_from_issue',
                'valid_days' => null,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['valid_days']);
    }

    /**
     * 수정: valid_type 은 그대로(days_from_issue) 두고 valid_days 만 비워도 거부된다.
     *
     * valid_type 이 요청에 없으므로, 저장돼 있던 값을 승계해 판정해야 잡힌다.
     *
     * @effects coupon_update_rejects_days_from_issue_without_valid_days
     */
    public function test_update_rejects_clearing_valid_days_when_existing_type_is_days_from_issue(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => 30]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}", $this->updatePayload([
                'valid_days' => null,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['valid_days']);

        $this->assertSame(30, $coupon->fresh()->valid_days);
    }

    /**
     * 수정: valid_type 만 days_from_issue 로 보내도 저장된 valid_days 가 있으면 통과한다.
     *
     * @effects coupon_update_rejects_days_from_issue_without_valid_days
     */
    public function test_update_allows_type_change_when_stored_valid_days_exists(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => 15]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}", $this->updatePayload([
                'valid_type' => 'days_from_issue',
            ]));

        $response->assertStatus(200);
        $this->assertSame(15, $coupon->fresh()->valid_days);
    }

    /**
     * 수정: 유효기간과 무관한 부분 수정은 차단되지 않는다. (과잉 차단 회귀 고정)
     *
     * 이미 valid_days 가 비어 있는 기존 쿠폰이라도, 요청이 유효기간 쌍을 건드리지
     * 않으면 통과해야 한다 — 그렇지 않으면 이름 변경조차 막힌다.
     *
     * @effects coupon_update_rejects_days_from_issue_without_valid_days
     */
    public function test_update_unrelated_field_is_not_blocked_on_legacy_broken_coupon(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => null]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}", $this->updatePayload([
                'name' => ['ko' => '이름만 변경', 'en' => 'Name only'],
            ]));

        $response->assertStatus(200);
    }

    /**
     * 발급 관문: 이미 저장돼 있던 (days_from_issue, valid_days=null) 쿠폰은 발급이 차단된다.
     *
     * 수정 전에는 만료일이 발급 시각과 같아져 즉시 만료된 쿠폰이 조용히 발급됐다.
     *
     * @effects coupon_issue_blocked_when_validity_not_configured
     */
    public function test_issue_is_blocked_when_valid_days_is_null(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => null]);

        $this->assertFalse($coupon->hasResolvableValidity());
        $this->assertFalse($coupon->isIssuable());

        $this->expectExceptionMessage(
            __('sirsoft-ecommerce::messages.coupon.validity_not_configured')
        );

        app(UserCouponService::class)->assertIssuable($coupon);
    }

    /**
     * 발급 관문: 0 일도 만료일을 만들 수 없으므로 동일하게 차단된다.
     *
     * @effects coupon_issue_blocked_when_validity_not_configured
     */
    public function test_issue_is_blocked_when_valid_days_is_zero(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => 0]);

        $this->assertFalse($coupon->isIssuable());

        $this->expectExceptionMessage(
            __('sirsoft-ecommerce::messages.coupon.validity_not_configured')
        );

        app(UserCouponService::class)->assertIssuable($coupon);
    }

    /**
     * 발급 관문: 기간지정 쿠폰은 valid_days 가 비어 있어도 영향받지 않는다. (과잉 차단 방지)
     *
     * @effects coupon_issue_blocked_when_validity_not_configured
     */
    public function test_period_coupon_is_unaffected_by_empty_valid_days(): void
    {
        $coupon = $this->makeCoupon([
            'valid_type' => 'period',
            'valid_days' => null,
            'valid_from' => now(),
            'valid_to' => now()->addMonth(),
        ]);

        $this->assertTrue($coupon->hasResolvableValidity());
        $this->assertTrue($coupon->isIssuable());
    }

    /**
     * 발급: 정상값은 그대로 발급일 + N일이 된다. (기준선)
     *
     * @effects coupon_issue_blocked_when_validity_not_configured
     */
    public function test_issue_uses_valid_days_when_configured(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => 7]);
        $member = User::factory()->create();

        $issue = app(UserCouponService::class)->issueDirectlyToUser($coupon, $member->id);

        $this->assertNotNull($issue->expired_at);
        $this->assertSame(
            now()->addDays(7)->format('Y-m-d'),
            $issue->expired_at->format('Y-m-d')
        );
    }

    /**
     * 발급: 속성이 숫자 문자열로 도착해도 Carbon 경계에서 터지지 않는다.
     *
     * 이 계획이 제거한 크래시(숫자 문자열 → Carbon strict TypeError)와 같은 부류를
     * 모델 속성 경유 경로에서도 고정한다.
     *
     * @effects coupon_issue_blocked_when_validity_not_configured
     */
    public function test_issue_survives_numeric_string_valid_days(): void
    {
        $coupon = $this->makeCoupon(['valid_days' => 7]);
        $coupon->setAttribute('valid_days', '7');
        $member = User::factory()->create();

        $issue = app(UserCouponService::class)->issueDirectlyToUser($coupon, $member->id);

        $this->assertSame(
            now()->addDays(7)->format('Y-m-d'),
            $issue->expired_at->format('Y-m-d')
        );
    }
}
