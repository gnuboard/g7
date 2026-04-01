<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserMileageController Feature 테스트
 *
 * 사용자 마일리지 API를 테스트합니다.
 */
class UserMileageControllerTest extends ModuleTestCase
{
    // ========================================
    // 마일리지 잔액 조회 테스트 (balance)
    // ========================================

    /**
     * 인증된 사용자가 마일리지 잔액을 조회할 수 있습니다.
     */
    public function test_authenticated_user_can_get_mileage_balance(): void
    {
        // Given: 인증된 사용자
        $user = $this->createUser();

        // When: 마일리지 잔액 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/balance');

        // Then: 200 OK 및 마일리지 정보 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'mileage' => [
                    'available',
                    'pending',
                    'expiring_soon',
                    'expiring_date',
                    'total_earned',
                    'total_used',
                ],
            ],
        ]);
    }

    /**
     * 비인증 사용자는 마일리지 잔액을 조회할 수 없습니다.
     */
    public function test_unauthenticated_user_cannot_get_mileage_balance(): void
    {
        // Given: 비인증 상태

        // When: 마일리지 잔액 조회 시도
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/mileage/balance');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 마일리지 잔액은 기본값으로 0을 반환합니다.
     */
    public function test_mileage_balance_returns_zero_by_default(): void
    {
        // Given: 인증된 사용자 (마일리지 적립 내역 없음)
        $user = $this->createUser();

        // When: 마일리지 잔액 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/balance');

        // Then: available이 0
        $response->assertStatus(200);
        $response->assertJsonPath('data.mileage.available', 0);
    }

    // ========================================
    // 사용 가능한 최대 마일리지 조회 테스트 (maxUsable)
    // ========================================

    /**
     * 인증된 사용자가 사용 가능한 최대 마일리지를 조회할 수 있습니다.
     */
    public function test_authenticated_user_can_get_max_usable_mileage(): void
    {
        // Given: 인증된 사용자
        $user = $this->createUser();

        // When: 사용 가능한 최대 마일리지 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/max-usable?order_amount=50000');

        // Then: 200 OK 및 최대 마일리지 정보 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'max_usable',
                'available',
            ],
        ]);
    }

    /**
     * 비인증 사용자는 사용 가능한 최대 마일리지를 조회할 수 없습니다.
     */
    public function test_unauthenticated_user_cannot_get_max_usable_mileage(): void
    {
        // Given: 비인증 상태

        // When: 사용 가능한 최대 마일리지 조회 시도
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/mileage/max-usable?order_amount=50000');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 마일리지가 없을 때 사용 가능한 최대 마일리지는 0입니다.
     */
    public function test_max_usable_returns_zero_when_no_mileage(): void
    {
        // Given: 인증된 사용자 (마일리지 없음)
        $user = $this->createUser();

        // When: 사용 가능한 최대 마일리지 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/max-usable?order_amount=100000');

        // Then: max_usable이 0
        $response->assertStatus(200);
        $response->assertJsonPath('data.max_usable', 0);
        $response->assertJsonPath('data.available', 0);
    }

    /**
     * 주문 금액이 0일 때 사용 가능한 최대 마일리지는 0입니다.
     */
    public function test_max_usable_returns_zero_when_order_amount_is_zero(): void
    {
        // Given: 인증된 사용자
        $user = $this->createUser();

        // When: 주문 금액 0으로 최대 마일리지 조회
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/max-usable?order_amount=0');

        // Then: max_usable이 0
        $response->assertStatus(200);
        $response->assertJsonPath('data.max_usable', 0);
    }
}
