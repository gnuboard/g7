<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 모듈 사용자 권한 테스트
 *
 * 블랙컨슈머 차단용 사용자 권한(상품 조회, 주문하기, 주문 취소)이
 * 라우트 미들웨어를 통해 올바르게 동작하는지 검증합니다.
 *
 * - 권한 있는 사용자: 정상 접근
 * - 권한 없는 사용자(블랙리스트): 403 차단
 * - 비인증 사용자: 공개 접근 허용 (optional.sanctum)
 */
class EcommerceUserPermissionTest extends ModuleTestCase
{
    private User $permittedUser;

    private User $blockedUser;

    private Role $permittedRole;

    private Role $blockedRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDefaultRoles();

        // 권한 생성
        $productReadPerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
                'type' => PermissionType::User,
            ]
        );

        $orderCreatePerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-orders.create'],
            [
                'name' => ['ko' => '주문하기', 'en' => 'Create Order'],
                'type' => PermissionType::User,
            ]
        );

        $orderCancelPerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-orders.cancel'],
            [
                'name' => ['ko' => '주문 취소', 'en' => 'Cancel Order'],
                'type' => PermissionType::User,
            ]
        );

        // 권한 있는 역할 생성
        $this->permittedRole = Role::create([
            'identifier' => 'test_permitted_role',
            'name' => ['ko' => '허용 역할', 'en' => 'Permitted Role'],
            'is_active' => true,
        ]);
        $this->permittedRole->permissions()->attach([
            $productReadPerm->id,
            $orderCreatePerm->id,
            $orderCancelPerm->id,
        ]);

        // 권한 없는 역할 생성 (블랙리스트)
        $this->blockedRole = Role::create([
            'identifier' => 'test_blocked_role',
            'name' => ['ko' => '차단 역할', 'en' => 'Blocked Role'],
            'is_active' => true,
        ]);
        // 권한 할당 없음

        // guest 역할에도 상품 조회 권한 부여 (비인증 사용자 공개 접근)
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $guestRole->permissions()->syncWithoutDetaching([$productReadPerm->id]);

        // PermissionMiddleware의 guest role 정적 캐시 초기화
        $reflection = new \ReflectionClass(\App\Http\Middleware\PermissionMiddleware::class);
        $prop = $reflection->getProperty('guestRoleCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // 사용자 생성
        $this->permittedUser = User::factory()->create();
        $this->permittedUser->roles()->attach($this->permittedRole->id);

        $this->blockedUser = User::factory()->create();
        $this->blockedUser->roles()->attach($this->blockedRole->id);
    }

    // ========================================================================
    // 상품 조회 권한 (sirsoft-ecommerce.user-products.read)
    // ========================================================================

    /**
     * 권한 있는 사용자의 상품 목록 조회는 200이어야 합니다.
     */
    public function test_permitted_user_can_view_products(): void
    {
        Product::factory()->onSale()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/products');

        $response->assertStatus(200);
    }

    /**
     * 권한 없는 사용자(블랙리스트)의 상품 목록 조회는 403이어야 합니다.
     */
    public function test_blocked_user_cannot_view_products(): void
    {
        $response = $this->actingAs($this->blockedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/products');

        $response->assertStatus(403);
    }

    /**
     * 비인증 사용자의 상품 목록 조회는 200이어야 합니다 (공개 접근).
     */
    public function test_guest_can_view_products(): void
    {
        Product::factory()->onSale()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        $response->assertStatus(200);
    }

    /**
     * 권한 있는 사용자의 상품 상세 조회는 200이어야 합니다.
     */
    public function test_permitted_user_can_view_product_detail(): void
    {
        $product = Product::factory()->onSale()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$product->id}");

        $response->assertStatus(200);
    }

    /**
     * 권한 없는 사용자(블랙리스트)의 상품 상세 조회는 403이어야 합니다.
     */
    public function test_blocked_user_cannot_view_product_detail(): void
    {
        $product = Product::factory()->onSale()->create();

        $response = $this->actingAs($this->blockedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$product->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 주문 생성 권한 (sirsoft-ecommerce.user-orders.create)
    // ========================================================================

    /**
     * 권한 없는 사용자(블랙리스트)의 주문 생성은 403이어야 합니다.
     */
    public function test_blocked_user_cannot_create_order(): void
    {
        $response = $this->actingAs($this->blockedUser)
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', []);

        $response->assertStatus(403);
    }

    /**
     * 권한 있는 사용자의 주문 생성 요청은 403이 아니어야 합니다.
     * (422 유효성 검증 실패는 정상 — 권한 체크 통과를 의미)
     */
    public function test_permitted_user_passes_order_create_permission(): void
    {
        $response = $this->actingAs($this->permittedUser)
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', []);

        // 권한 체크 통과 → 422(유효성 실패) 또는 다른 응답, 403은 아님
        $this->assertNotEquals(403, $response->status());
    }
}
