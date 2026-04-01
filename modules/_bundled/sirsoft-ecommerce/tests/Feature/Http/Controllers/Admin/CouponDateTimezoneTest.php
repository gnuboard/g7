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
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 날짜 타임존 변환 테스트
 *
 * 프론트엔드에서 Y-m-d 형식으로 전달된 날짜가 사이트 기본 타임존 기준으로
 * 시작일 00:00:00, 종료일 23:59:59로 해석되어 UTC로 변환 저장되는지 검증합니다.
 * 또한 API 응답에서 Y-m-d 형식(사이트 타임존 기준)으로 반환되는지 검증합니다.
 */
class CouponDateTimezoneTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 쿠폰 생성 시 필요한 기본 데이터
     *
     * @param array $overrides 오버라이드할 속성
     * @return array
     */
    private function validCouponData(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => '타임존 테스트 쿠폰', 'en' => 'Timezone Test Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'period',
            'valid_from' => '2026-03-15',
            'valid_to' => '2026-03-20',
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────
    // 생성 시 날짜 저장 검증
    // ─────────────────────────────────────────────────────────

    /**
     * 유효기간 시작일이 사이트 타임존 00:00:00 기준 UTC로 저장되는지 검증
     *
     * 2026-03-15 + Asia/Seoul → 2026-03-15 00:00:00 KST → 2026-03-14 15:00:00 UTC
     */
    public function test_store_valid_from_saves_as_site_timezone_start_of_day_utc(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());

        $response->assertStatus(201);

        $coupon = Coupon::latest('id')->first();
        $this->assertEquals('2026-03-14 15:00:00', $coupon->valid_from->format('Y-m-d H:i:s'));
    }

    /**
     * 유효기간 종료일이 사이트 타임존 23:59:59 기준 UTC로 저장되는지 검증
     *
     * 2026-03-20 + Asia/Seoul → 2026-03-20 23:59:59 KST → 2026-03-20 14:59:59 UTC
     */
    public function test_store_valid_to_saves_as_site_timezone_end_of_day_utc(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());

        $response->assertStatus(201);

        $coupon = Coupon::latest('id')->first();
        $this->assertEquals('2026-03-20 14:59:59', $coupon->valid_to->format('Y-m-d H:i:s'));
    }

    /**
     * 발급기간 시작일/종료일이 datetime-local 형식으로 사이트 타임존 → UTC 변환되는지 검증
     *
     * 2026-04-01T09:00 KST = 2026-04-01 00:00:00 UTC
     * 2026-04-30T18:00 KST = 2026-04-30 09:00:00 UTC
     */
    public function test_store_issue_dates_save_with_site_timezone_conversion(): void
    {
        $data = $this->validCouponData([
            'issue_from' => '2026-04-01T09:00',
            'issue_to' => '2026-04-30T18:00',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);

        $coupon = Coupon::latest('id')->first();
        // 2026-04-01 09:00:00 KST = 2026-04-01 00:00:00 UTC
        $this->assertEquals('2026-04-01 00:00:00', $coupon->issue_from->format('Y-m-d H:i:s'));
        // 2026-04-30 18:00:00 KST = 2026-04-30 09:00:00 UTC
        $this->assertEquals('2026-04-30 09:00:00', $coupon->issue_to->format('Y-m-d H:i:s'));
    }

    // ─────────────────────────────────────────────────────────
    // API 응답 Y-m-d 형식 검증
    // ─────────────────────────────────────────────────────────

    /**
     * API 응답에서 유효기간은 Y-m-d, 발급기간은 Y-m-d\TH:i 형식으로 반환되는지 검증
     */
    public function test_show_returns_dates_in_correct_format_site_timezone(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData([
                'issue_from' => '2026-04-01T09:00',
                'issue_to' => '2026-04-30T18:00',
            ]));

        $coupon = Coupon::latest('id')->first();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons/'.$coupon->id);

        $response->assertStatus(200);

        // 유효기간: Y-m-d 형식 (왕복 변환)
        $response->assertJsonPath('data.valid_from', '2026-03-15');
        $response->assertJsonPath('data.valid_to', '2026-03-20');
        // 발급기간: Y-m-d\TH:i 형식 (왕복 변환)
        $response->assertJsonPath('data.issue_from', '2026-04-01T09:00');
        $response->assertJsonPath('data.issue_to', '2026-04-30T18:00');
    }

    // ─────────────────────────────────────────────────────────
    // 수정 시 날짜 변환 검증
    // ─────────────────────────────────────────────────────────

    /**
     * 쿠폰 수정 시에도 날짜가 사이트 타임존 기준 UTC로 변환되는지 검증
     */
    public function test_update_dates_save_with_site_timezone_conversion(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());

        $coupon = Coupon::latest('id')->first();

        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons/'.$coupon->id, $this->validCouponData([
                'valid_from' => '2026-06-01',
                'valid_to' => '2026-06-30',
            ]));

        $response->assertStatus(200);

        $coupon->refresh();
        // 2026-06-01 00:00:00 KST = 2026-05-31 15:00:00 UTC
        $this->assertEquals('2026-05-31 15:00:00', $coupon->valid_from->format('Y-m-d H:i:s'));
        // 2026-06-30 23:59:59 KST = 2026-06-30 14:59:59 UTC
        $this->assertEquals('2026-06-30 14:59:59', $coupon->valid_to->format('Y-m-d H:i:s'));
    }

    // ─────────────────────────────────────────────────────────
    // null 날짜 처리
    // ─────────────────────────────────────────────────────────

    /**
     * 발급기간이 null인 경우 정상 처리되는지 검증
     */
    public function test_store_with_null_issue_dates_saves_null(): void
    {
        $data = $this->validCouponData([
            'issue_from' => null,
            'issue_to' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);

        $coupon = Coupon::latest('id')->first();
        $this->assertNull($coupon->issue_from);
        $this->assertNull($coupon->issue_to);
    }
}
