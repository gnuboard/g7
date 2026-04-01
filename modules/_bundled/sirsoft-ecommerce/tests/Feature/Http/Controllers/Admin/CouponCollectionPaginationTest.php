<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponCollection/CouponIssueCollection 페이지네이션 응답 테스트
 *
 * API 응답에 pagination 메타데이터가 포함되는지 검증
 */
class CouponCollectionPaginationTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
        ]);
    }

    /**
     * 테스트용 쿠폰 생성 헬퍼
     *
     * @param  array  $attributes  오버라이드할 속성
     * @return Coupon
     */
    private function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'code' => 'TEST'.uniqid(),
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'max_discount_amount' => null,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'target_scope' => CouponTargetScope::ALL->value,
            'max_issues' => 100,
            'max_issues_per_user' => 1,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 쿠폰 목록 API 응답에 pagination 메타데이터가 포함되는지 검증
     */
    public function test_coupon_list_includes_pagination(): void
    {
        // 테스트 데이터 생성
        for ($i = 0; $i < 3; $i++) {
            $this->createCoupon(['name' => ['ko' => "쿠폰 {$i}", 'en' => "Coupon {$i}"]]);
        }

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'abilities',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                ],
            ]);

        // pagination 값 검증
        $pagination = $response->json('data.pagination');
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(2, $pagination['per_page']);
        $this->assertGreaterThanOrEqual(3, $pagination['total']);
        $this->assertTrue($pagination['has_more_pages']);
    }

    /**
     * 쿠폰 발급 내역 API 응답에 pagination 메타데이터가 포함되는지 검증
     */
    public function test_coupon_issues_list_includes_pagination(): void
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        // 발급 내역 3건 생성
        for ($i = 0; $i < 3; $i++) {
            CouponIssue::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'status' => CouponIssueRecordStatus::AVAILABLE->value,
                'issued_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues?per_page=2");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                ],
            ]);

        // pagination 값 검증
        $pagination = $response->json('data.pagination');
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(2, $pagination['per_page']);
        $this->assertEquals(3, $pagination['total']);
        $this->assertTrue($pagination['has_more_pages']);
    }

    /**
     * 쿠폰 목록 2페이지 요청 시 pagination이 올바르게 업데이트되는지 검증
     */
    public function test_coupon_list_pagination_page_2(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createCoupon(['name' => ['ko' => "쿠폰 {$i}", 'en' => "Coupon {$i}"]]);
        }

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?per_page=2&page=2');

        $response->assertOk();

        $pagination = $response->json('data.pagination');
        $this->assertEquals(2, $pagination['current_page']);
        $this->assertEquals(2, $pagination['per_page']);
        $this->assertGreaterThanOrEqual(5, $pagination['total']);
    }
}
