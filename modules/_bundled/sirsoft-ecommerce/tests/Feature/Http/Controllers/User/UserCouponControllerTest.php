<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use Carbon\Carbon;
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
 * UserCouponController Feature 테스트
 *
 * 사용자 쿠폰함 API를 테스트합니다.
 */
class UserCouponControllerTest extends ModuleTestCase
{
    /**
     * 테스트용 쿠폰을 생성합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return Coupon
     */
    protected function createCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트 쿠폰 설명', 'en' => 'Test coupon description'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 10000,
            'issue_status' => CouponIssueStatus::ISSUING,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    /**
     * 테스트용 쿠폰 발급 내역을 생성합니다.
     *
     * @param Coupon $coupon 쿠폰
     * @param int $userId 사용자 ID
     * @param array $overrides 오버라이드할 속성
     * @return CouponIssue
     */
    protected function createCouponIssue(Coupon $coupon, int $userId, array $overrides = []): CouponIssue
    {
        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'coupon_code' => 'TEST-'.strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    // ========================================
    // 쿠폰함 목록 조회 테스트 (index)
    // ========================================

    /**
     * 인증된 사용자가 쿠폰함 목록을 조회할 수 있습니다.
     */
    public function test_authenticated_user_can_list_coupons(): void
    {
        // Given: 인증된 사용자와 쿠폰 발급 내역
        $user = $this->createUser();
        $coupon = $this->createCoupon();
        $this->createCouponIssue($coupon, $user->id);

        // When: 쿠폰함 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons');

        // Then: 200 OK 및 쿠폰 목록 반환 — 스키마 구조 상세 확인보다는 data 키 존재 여부만 검증
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'coupons',
            ],
        ]);
        $this->assertIsArray($response->json('data.coupons'));
    }

    /**
     * 비인증 사용자는 쿠폰함 목록을 조회할 수 없습니다.
     */
    public function test_unauthenticated_user_cannot_list_coupons(): void
    {
        // Given: 비인증 상태

        // When: 쿠폰함 목록 조회 시도
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/coupons');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 사용 가능한 쿠폰만 필터링하여 조회할 수 있습니다.
     */
    public function test_can_filter_available_coupons(): void
    {
        // Given: 인증된 사용자와 상태가 다른 쿠폰들
        $user = $this->createUser();
        $coupon1 = $this->createCoupon(['name' => ['ko' => '사용가능 쿠폰', 'en' => 'Available Coupon']]);
        $coupon2 = $this->createCoupon(['name' => ['ko' => '사용완료 쿠폰', 'en' => 'Used Coupon']]);

        $this->createCouponIssue($coupon1, $user->id, [
            'status' => CouponIssueRecordStatus::AVAILABLE,
        ]);
        $this->createCouponIssue($coupon2, $user->id, [
            'status' => CouponIssueRecordStatus::USED,
            'used_at' => Carbon::now(),
        ]);

        // When: 사용 가능한 쿠폰만 필터링
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons?status=available');

        // Then: 사용 가능한 쿠폰만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.coupons.data');
    }

    /**
     * 다른 사용자의 쿠폰은 조회되지 않습니다.
     */
    public function test_cannot_see_other_users_coupons(): void
    {
        // Given: 두 명의 사용자와 각각의 쿠폰
        $user1 = $this->createUser();
        $user2 = $this->createUser();
        $coupon = $this->createCoupon();

        $this->createCouponIssue($coupon, $user1->id);
        $this->createCouponIssue($coupon, $user2->id);

        // When: user1이 쿠폰함 조회
        $response = $this->actingAs($user1, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons');

        // Then: 본인 쿠폰만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.coupons.data');
    }

    // ========================================
    // 사용 가능한 쿠폰 목록 조회 테스트 (available)
    // ========================================

    /**
     * 인증된 사용자가 사용 가능한 쿠폰 목록을 조회할 수 있습니다.
     */
    public function test_authenticated_user_can_get_available_coupons(): void
    {
        // Given: 인증된 사용자와 쿠폰 발급 내역
        $user = $this->createUser();
        $coupon = $this->createCoupon();
        $this->createCouponIssue($coupon, $user->id);

        // When: 사용 가능한 쿠폰 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/available');

        // Then: 200 OK 및 쿠폰 목록 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'coupons',
            ],
        ]);
    }

    /**
     * 비인증 사용자는 사용 가능한 쿠폰 목록을 조회할 수 없습니다.
     */
    public function test_unauthenticated_user_cannot_get_available_coupons(): void
    {
        // Given: 비인증 상태

        // When: 사용 가능한 쿠폰 목록 조회 시도
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/coupons/available');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 만료된 쿠폰은 사용 가능한 쿠폰 목록에 포함되지 않습니다.
     */
    public function test_expired_coupons_not_in_available_list(): void
    {
        // Given: 인증된 사용자와 만료된 쿠폰
        $user = $this->createUser();
        $coupon = $this->createCoupon();
        $this->createCouponIssue($coupon, $user->id, [
            'expired_at' => Carbon::now()->subDay(), // 만료됨
        ]);

        // When: 사용 가능한 쿠폰 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/available');

        // Then: 빈 목록 반환
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.coupons');
    }

    /**
     * 이미 사용한 쿠폰은 사용 가능한 쿠폰 목록에 포함되지 않습니다.
     */
    public function test_used_coupons_not_in_available_list(): void
    {
        // Given: 인증된 사용자와 사용 완료된 쿠폰
        $user = $this->createUser();
        $coupon = $this->createCoupon();
        $this->createCouponIssue($coupon, $user->id, [
            'status' => CouponIssueRecordStatus::USED,
            'used_at' => Carbon::now(),
        ]);

        // When: 사용 가능한 쿠폰 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/available');

        // Then: 빈 목록 반환
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.coupons');
    }

    // ========================================
    // 다운로드 가능 쿠폰 목록 조회 테스트 (downloadable)
    // ========================================

    /**
     * 다운로드 가능한 쿠폰을 생성합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return Coupon
     */
    protected function createDownloadableCoupon(array $overrides = []): Coupon
    {
        return $this->createCoupon(array_merge([
            'issue_method' => CouponIssueMethod::DOWNLOAD,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'issue_from' => Carbon::now()->subDay(),
            'issue_to' => Carbon::now()->addMonth(),
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
            'valid_type' => 'period',
        ], $overrides));
    }

    /**
     * 인증된 사용자가 다운로드 가능한 쿠폰 목록을 조회할 수 있습니다.
     */
    public function test_authenticated_user_can_list_downloadable_coupons(): void
    {
        // Given: 인증된 사용자와 다운로드 가능 쿠폰
        $user = $this->createUser();
        $this->createDownloadableCoupon();

        // When: 다운로드 가능 쿠폰 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/downloadable');

        // Then: 200 OK 및 쿠폰 목록 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data',
            ],
        ]);
    }

    /**
     * 비인증 사용자는 다운로드 가능 쿠폰 목록을 조회할 수 없습니다.
     */
    public function test_unauthenticated_user_cannot_list_downloadable_coupons(): void
    {
        // When: 비인증 상태로 조회 시도
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/coupons/downloadable');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 이미 다운로드한 쿠폰은 is_downloaded=true로 표시됩니다.
     */
    public function test_downloaded_coupon_shows_is_downloaded_true(): void
    {
        // Given: 인증된 사용자와 이미 다운로드한 쿠폰
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon();
        $this->createCouponIssue($coupon, $user->id);

        // When: 다운로드 가능 쿠폰 목록 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/downloadable');

        // Then: is_downloaded=true
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertTrue($data[0]['is_downloaded']);
    }

    /**
     * 페이지네이션이 정상 동작합니다.
     */
    public function test_downloadable_coupons_pagination_works(): void
    {
        // Given: 10개의 다운로드 가능 쿠폰
        $user = $this->createUser();
        for ($i = 0; $i < 10; $i++) {
            $this->createDownloadableCoupon([
                'name' => ['ko' => "쿠폰 {$i}", 'en' => "Coupon {$i}"],
            ]);
        }

        // When: per_page=8로 첫 페이지 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/coupons/downloadable?per_page=8&page=1');

        // Then: 8개만 반환
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(8, $data);
    }

    // ========================================
    // 쿠폰 다운로드 테스트 (download)
    // ========================================

    /**
     * 쿠폰 다운로드가 성공합니다.
     */
    public function test_coupon_download_succeeds(): void
    {
        // Given: 인증된 사용자와 다운로드 가능 쿠폰
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon();

        // When: 쿠폰 다운로드
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 201 Created 및 CouponIssue 생성
        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_promotion_coupon_issues', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE->value,
        ]);

        // issued_count 증가 확인
        $this->assertEquals(1, $coupon->fresh()->issued_count);
    }

    /**
     * per_user_limit 초과 시 다운로드가 차단됩니다.
     */
    public function test_download_blocked_when_per_user_limit_exceeded(): void
    {
        // Given: per_user_limit=1인 쿠폰을 이미 1회 다운로드
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon(['per_user_limit' => 1]);
        $this->createCouponIssue($coupon, $user->id);

        // When: 다시 다운로드 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 400 에러
        $response->assertStatus(400);
    }

    /**
     * per_user_limit=0(무제한)이면 동일 쿠폰을 여러 번 다운로드할 수 있습니다.
     */
    public function test_download_allowed_when_per_user_limit_is_zero(): void
    {
        // Given: per_user_limit=0(무제한)인 쿠폰을 이미 3회 다운로드
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon(['per_user_limit' => 0]);
        $this->createCouponIssue($coupon, $user->id);
        $this->createCouponIssue($coupon, $user->id);
        $this->createCouponIssue($coupon, $user->id);

        // When: 추가 다운로드 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 정상 다운로드 (201 Created)
        $response->assertStatus(201);
    }

    /**
     * 수량 소진 시 다운로드가 차단됩니다.
     */
    public function test_download_blocked_when_quantity_exhausted(): void
    {
        // Given: 수량이 소진된 쿠폰
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon([
            'total_quantity' => 1,
            'issued_count' => 1,
        ]);

        // When: 다운로드 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 400 에러
        $response->assertStatus(400);
    }

    /**
     * 발급기간 외에는 다운로드가 차단됩니다.
     */
    public function test_download_blocked_when_issue_period_expired(): void
    {
        // Given: 발급기간이 종료된 쿠폰
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon([
            'issue_from' => Carbon::now()->subMonth(),
            'issue_to' => Carbon::now()->subDay(),
        ]);

        // When: 다운로드 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 400 에러
        $response->assertStatus(400);
    }

    /**
     * 발급중단 쿠폰은 다운로드가 차단됩니다.
     */
    public function test_download_blocked_when_issue_stopped(): void
    {
        // Given: 발급중단 상태 쿠폰
        $user = $this->createUser();
        $coupon = $this->createDownloadableCoupon([
            'issue_status' => CouponIssueStatus::STOPPED,
        ]);

        // When: 다운로드 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 400 에러
        $response->assertStatus(400);
    }

    /**
     * 비인증 사용자는 쿠폰 다운로드가 차단됩니다.
     */
    public function test_unauthenticated_user_cannot_download_coupon(): void
    {
        // Given: 다운로드 가능 쿠폰
        $coupon = $this->createDownloadableCoupon();

        // When: 비인증 상태로 다운로드 시도
        $response = $this->postJson("/api/modules/sirsoft-ecommerce/user/coupons/{$coupon->id}/download");

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }
}
