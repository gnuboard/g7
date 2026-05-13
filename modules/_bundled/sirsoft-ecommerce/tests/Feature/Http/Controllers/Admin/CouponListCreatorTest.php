<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 리스트 등록자 필드 및 검색 필터 테스트
 *
 * CouponResource의 등록자 플랫 필드(created_by, created_by_name, created_by_email)와
 * CouponRepository의 created_by 검색 필터를 검증합니다.
 */
class CouponListCreatorTest extends ModuleTestCase
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
            'sirsoft-ecommerce.promotion-coupon.delete',
        ]);
    }

    /**
     * 테스트용 쿠폰 생성
     *
     * @param array $attributes 오버라이드할 속성
     * @return Coupon
     */
    private function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $this->adminUser->id,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────
    // 등록자 플랫 필드 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * 쿠폰 리스트 응답에 등록자 플랫 필드가 포함되는지 검증
     */
    public function test_coupon_list_includes_creator_flat_fields(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');

        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);

        // 생성한 쿠폰 찾기
        $couponData = collect($data)->firstWhere('id', $coupon->id);
        $this->assertNotNull($couponData, '생성한 쿠폰이 리스트에 있어야 합니다.');

        // 등록자 플랫 필드 존재 확인
        $this->assertArrayHasKey('created_by', $couponData);
        $this->assertArrayHasKey('created_by_name', $couponData);
        $this->assertArrayHasKey('created_by_email', $couponData);

        // 값 검증 (CouponResource 는 creator->uuid 노출)
        $this->assertEquals($this->adminUser->uuid, $couponData['created_by']);
        $this->assertEquals($this->adminUser->name, $couponData['created_by_name']);
        $this->assertEquals($this->adminUser->email, $couponData['created_by_email']);
    }

    /**
     * 쿠폰 상세 응답에 등록자 플랫 필드가 포함되는지 검증
     */
    public function test_coupon_detail_includes_creator_flat_fields(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}");

        $response->assertOk();

        $data = $response->json('data');

        $this->assertEquals($this->adminUser->uuid, $data['created_by']);
        $this->assertEquals($this->adminUser->name, $data['created_by_name']);
        $this->assertEquals($this->adminUser->email, $data['created_by_email']);
    }

    /**
     * 등록자가 없는(created_by=null) 쿠폰은 하이픈 표시
     */
    public function test_coupon_without_creator_shows_dash(): void
    {
        $coupon = $this->createCoupon(['created_by' => null]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');

        $response->assertOk();

        $data = $response->json('data.data');
        $couponData = collect($data)->firstWhere('id', $coupon->id);

        // created_by가 null인 경우 created_by_name은 '-'
        $this->assertEquals('-', $couponData['created_by_name']);
    }

    // ─────────────────────────────────────────────────────────
    // 등록자 검색 필터 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * 등록자 이름으로 검색 시 해당 쿠폰만 반환되는지 검증
     */
    public function test_search_by_creator_name(): void
    {
        // 다른 사용자 생성 및 각각 쿠폰 생성
        $otherUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);
        $otherUser->name = 'OtherCreatorUser';
        $otherUser->save();

        $couponByAdmin = $this->createCoupon([
            'name' => ['ko' => '관리자 쿠폰', 'en' => 'Admin Coupon'],
            'created_by' => $this->adminUser->id,
        ]);
        $couponByOther = $this->createCoupon([
            'name' => ['ko' => '다른 사용자 쿠폰', 'en' => 'Other Coupon'],
            'created_by' => $otherUser->id,
        ]);

        // OtherCreatorUser 이름으로 검색
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'search_field' => 'created_by',
                'search_keyword' => 'OtherCreatorUser',
            ]));

        $response->assertOk();

        $data = $response->json('data.data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($couponByOther->id, $ids);
        $this->assertNotContains($couponByAdmin->id, $ids);
    }

    /**
     * 등록자 이메일로 검색 시 해당 쿠폰만 반환되는지 검증
     */
    public function test_search_by_creator_email(): void
    {
        $otherUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);
        $otherUser->email = 'unique-test-creator@example.com';
        $otherUser->save();

        $couponByAdmin = $this->createCoupon([
            'name' => ['ko' => '관리자 쿠폰2', 'en' => 'Admin Coupon2'],
            'created_by' => $this->adminUser->id,
        ]);
        $couponByOther = $this->createCoupon([
            'name' => ['ko' => '이메일 검색 쿠폰', 'en' => 'Email Search Coupon'],
            'created_by' => $otherUser->id,
        ]);

        // 이메일로 검색
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'search_field' => 'created_by',
                'search_keyword' => 'unique-test-creator@example.com',
            ]));

        $response->assertOk();

        $data = $response->json('data.data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($couponByOther->id, $ids);
        $this->assertNotContains($couponByAdmin->id, $ids);
    }

    /**
     * 전체 검색(search_field=all)에서도 등록자 이름으로 검색 가능한지 검증
     */
    public function test_search_all_includes_creator_name(): void
    {
        $otherUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);
        $otherUser->name = 'UniqueCreator123';
        $otherUser->save();

        $coupon = $this->createCoupon([
            'name' => ['ko' => '일반 쿠폰', 'en' => 'Normal Coupon'],
            'created_by' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'search_field' => 'all',
                'search_keyword' => 'UniqueCreator123',
            ]));

        $response->assertOk();

        $data = $response->json('data.data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($coupon->id, $ids);
    }

    // ─────────────────────────────────────────────────────────
    // created_by 직접 필터 테스트 (사용자 ID)
    // ─────────────────────────────────────────────────────────

    /**
     * created_by 파라미터로 특정 등록자의 쿠폰만 필터링되는지 검증
     */
    public function test_filter_by_created_by_user_id(): void
    {
        $otherUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);

        $couponByAdmin = $this->createCoupon([
            'name' => ['ko' => '관리자 쿠폰', 'en' => 'Admin Coupon'],
            'created_by' => $this->adminUser->id,
        ]);
        $couponByOther = $this->createCoupon([
            'name' => ['ko' => '다른 사용자 쿠폰', 'en' => 'Other Coupon'],
            'created_by' => $otherUser->id,
        ]);

        // created_by 파라미터로 필터 — CouponListRequest 는 UUID 필수
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'created_by' => $otherUser->uuid,
            ]));

        $response->assertOk();

        $data = $response->json('data.data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($couponByOther->id, $ids);
        $this->assertNotContains($couponByAdmin->id, $ids);
    }

    /**
     * created_by에 존재하지 않는 사용자 ID를 전달하면 빈 결과를 반환하는지 검증
     */
    public function test_filter_by_nonexistent_created_by_returns_empty(): void
    {
        $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'created_by' => 99999,
            ]));

        // 존재하지 않는 사용자 ID → 유효성 검증 실패
        $response->assertUnprocessable();
    }

    /**
     * created_by 파라미터가 정수가 아닌 경우 유효성 검증 실패하는지 검증
     */
    public function test_filter_by_invalid_created_by_fails_validation(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?' . http_build_query([
                'created_by' => 'not-a-number',
            ]));

        $response->assertUnprocessable();
    }

    // ─────────────────────────────────────────────────────────
    // 작업 컬럼 (수정/삭제) 테스트
    // ─────────────────────────────────────────────────────────

    /**
     * 쿠폰 삭제 API가 정상 동작하는지 검증
     */
    public function test_coupon_can_be_deleted(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}");

        $response->assertOk();

        // soft delete 확인
        $this->assertSoftDeleted('ecommerce_promotion_coupons', ['id' => $coupon->id]);
    }

    /**
     * 쿠폰 리스트에 abilities(can_update, can_delete)가 포함되는지 검증
     */
    public function test_coupon_list_includes_abilities(): void
    {
        $coupon = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');

        $response->assertOk();

        // 개별 아이템 abilities
        $data = $response->json('data.data');
        $couponData = collect($data)->firstWhere('id', $coupon->id);
        $this->assertArrayHasKey('abilities', $couponData);
        $this->assertArrayHasKey('can_update', $couponData['abilities']);
        $this->assertArrayHasKey('can_delete', $couponData['abilities']);

        // 컬렉션 레벨 abilities
        $this->assertArrayHasKey('abilities', $response->json('data'));
    }
}
