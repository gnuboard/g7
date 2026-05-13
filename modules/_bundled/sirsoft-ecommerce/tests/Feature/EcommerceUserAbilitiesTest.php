<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 모듈 사용자 abilities 테스트
 *
 * 주문/배송지 API 응답에 포함되는 abilities 메타 데이터가
 * 상태, 권한, 조건에 따라 올바르게 반환되는지 검증합니다.
 *
 * - 주문 목록: can_cancel (상태 + 권한 기반)
 * - 주문 상세: can_cancel (상태 + 권한 기반, OrderResource)
 * - 주문 컬렉션: can_create (권한 기반)
 * - 배송지 목록: can_delete (기본 배송지 여부)
 * - 배송지 리소스: can_update, can_set_default
 * - 주문 취소 API: 권한/상태별 동작 검증
 */
class EcommerceUserAbilitiesTest extends ModuleTestCase
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

        $productReadPerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
                'type' => PermissionType::User,
            ]
        );

        // 권한 있는 역할 생성
        $this->permittedRole = Role::create([
            'identifier' => 'test_abilities_permitted_role',
            'name' => ['ko' => '허용 역할', 'en' => 'Permitted Role'],
            'is_active' => true,
        ]);
        $this->permittedRole->permissions()->attach([
            $orderCreatePerm->id,
            $orderCancelPerm->id,
            $productReadPerm->id,
        ]);

        // 권한 없는 역할 생성 (블랙리스트)
        $this->blockedRole = Role::create([
            'identifier' => 'test_abilities_blocked_role',
            'name' => ['ko' => '차단 역할', 'en' => 'Blocked Role'],
            'is_active' => true,
        ]);
        // 블랙리스트에는 권한 할당 없음

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
    // 주문 목록 abilities (can_cancel)
    // ========================================================================

    /**
     * 취소 가능 상태(pending_payment)의 주문은 can_cancel이 true여야 합니다.
     *
     * 주의: pending_order 상태는 목록에서 기본 제외되므로 pending_payment으로 테스트합니다.
     */
    public function test_order_list_returns_can_cancel_true_for_cancellable_order(): void
    {
        Order::factory()->forUser($this->permittedUser)->pendingPayment()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.abilities.can_cancel', true);
    }

    /**
     * 취소 불가 상태(shipping)의 주문은 can_cancel이 false여야 합니다.
     */
    public function test_order_list_returns_can_cancel_false_for_shipped_order(): void
    {
        Order::factory()->forUser($this->permittedUser)->shipping()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.abilities.can_cancel', false);
    }

    /**
     * 취소 권한이 없는 사용자의 주문은 can_cancel이 false여야 합니다.
     */
    public function test_order_list_returns_can_cancel_false_without_cancel_permission(): void
    {
        Order::factory()->forUser($this->blockedUser)->pendingPayment()->create();

        $response = $this->actingAs($this->blockedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.abilities.can_cancel', false);
    }

    // ========================================================================
    // 주문 상세 abilities (can_cancel — OrderResource)
    // ========================================================================

    /**
     * 취소 가능 상태의 주문 상세에서 can_cancel이 true여야 합니다.
     */
    public function test_order_detail_returns_can_cancel_true_for_cancellable_order(): void
    {
        $order = Order::factory()->forUser($this->permittedUser)->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_cancel', true);
    }

    /**
     * 취소 불가 상태(shipping)의 주문 상세에서 can_cancel이 false여야 합니다.
     */
    public function test_order_detail_returns_can_cancel_false_for_shipped_order(): void
    {
        $order = Order::factory()->forUser($this->permittedUser)->shipping()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_cancel', false);
    }

    /**
     * 취소 권한이 없는 사용자의 주문 상세에서 can_cancel이 false여야 합니다.
     */
    public function test_order_detail_returns_can_cancel_false_without_cancel_permission(): void
    {
        $order = Order::factory()->forUser($this->blockedUser)->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($this->blockedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_cancel', false);
    }

    // ========================================================================
    // 주문 컬렉션 abilities (can_create)
    // ========================================================================

    /**
     * 주문 생성 권한이 있는 사용자의 주문 컬렉션에 can_create가 true여야 합니다.
     */
    public function test_order_collection_returns_can_create_true_for_permitted_user(): void
    {
        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_create', true);
    }

    /**
     * 주문 생성 권한이 없는 사용자의 주문 컬렉션에 can_create가 false여야 합니다.
     */
    public function test_order_collection_returns_can_create_false_for_blocked_user(): void
    {
        $response = $this->actingAs($this->blockedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_create', false);
    }

    // ========================================================================
    // 배송지 abilities (can_delete, can_update, can_set_default)
    // ========================================================================

    /**
     * 기본 배송지는 can_delete가 false여야 합니다.
     */
    public function test_address_list_returns_can_delete_false_for_default_address(): void
    {
        UserAddress::factory()->forUser($this->permittedUser)->default()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/addresses');

        $response->assertStatus(200);
        $response->assertJsonPath('data.addresses.data.0.abilities.can_delete', false);
    }

    /**
     * 기본 배송지가 아닌 배송지는 can_delete가 true여야 합니다.
     */
    public function test_address_list_returns_can_delete_true_for_non_default_address(): void
    {
        UserAddress::factory()->forUser($this->permittedUser)->create([
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->permittedUser)
            ->getJson('/api/modules/sirsoft-ecommerce/user/addresses');

        $response->assertStatus(200);
        $response->assertJsonPath('data.addresses.data.0.abilities.can_delete', true);
    }

    /**
     * 배송지 리소스에 can_update가 true로 반환되어야 합니다.
     */
    public function test_address_resource_returns_can_update_true(): void
    {
        $address = UserAddress::factory()->forUser($this->permittedUser)->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/addresses/{$address->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.address.abilities.can_update', true);
    }

    /**
     * 기본 배송지가 아닌 배송지는 can_set_default가 true여야 합니다.
     */
    public function test_address_resource_returns_can_set_default_true_for_non_default(): void
    {
        $address = UserAddress::factory()->forUser($this->permittedUser)->create([
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/addresses/{$address->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.address.abilities.can_set_default', true);
    }

    /**
     * 기본 배송지는 can_set_default가 false여야 합니다.
     */
    public function test_address_resource_returns_can_set_default_false_for_default(): void
    {
        $address = UserAddress::factory()->forUser($this->permittedUser)->default()->create();

        $response = $this->actingAs($this->permittedUser)
            ->getJson("/api/modules/sirsoft-ecommerce/user/addresses/{$address->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.address.abilities.can_set_default', false);
    }

    // ========================================================================
    // 주문 취소 API 엔드포인트
    // ========================================================================

    /**
     * 권한 있는 사용자는 취소 가능 상태의 주문을 취소할 수 있어야 합니다.
     */
    public function test_permitted_user_can_cancel_cancellable_order(): void
    {
        $order = Order::factory()->forUser($this->permittedUser)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $response = $this->actingAs($this->permittedUser)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel", [
                'reason' => 'changed_mind',
            ]);

        $response->assertStatus(200);

        // DB에서 주문 상태 확인
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 권한 없는 사용자(블랙리스트)의 주문 취소는 403이어야 합니다.
     */
    public function test_blocked_user_cannot_cancel_order(): void
    {
        $order = Order::factory()->forUser($this->blockedUser)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $response = $this->actingAs($this->blockedUser)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel", [
                'reason' => 'changed_mind',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 취소 불가 상태의 주문 취소 요청은 422여야 합니다.
     */
    public function test_cancel_fails_for_non_cancellable_status(): void
    {
        $order = Order::factory()->forUser($this->permittedUser)->shipping()->create();

        $response = $this->actingAs($this->permittedUser)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel", [
                'reason' => 'changed_mind',
            ]);

        $response->assertStatus(422);
    }
}
