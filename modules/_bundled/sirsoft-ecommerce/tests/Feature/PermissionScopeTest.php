<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * Ecommerce 모듈 권한 스코프 테스트
 *
 * 미들웨어 scope_type 기반 상세 접근 체크 및 applyPermissionScope 목록 필터링을 검증합니다.
 */
class PermissionScopeTest extends ModuleTestCase
{
    private Permission $permission;

    private Role $testRole;

    private User $scopeUser;

    private User $otherUser;

    private User $sameRoleUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();

        // 테스트용 권한 생성
        $this->permission = Permission::create([
            'identifier' => 'test.ecommerce.scope',
            'name' => ['ko' => '이커머스 스코프 테스트', 'en' => 'Ecommerce Scope Test'],
            'type' => PermissionType::Admin,
            'resource_route_key' => 'product',
            'owner_key' => 'created_by',
        ]);

        // 사용자 생성
        $this->scopeUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->sameRoleUser = User::factory()->create();

        // 역할 생성 및 할당
        $this->testRole = Role::create([
            'identifier' => 'test_ecom_scope_role',
            'name' => ['ko' => '이커머스 스코프 역할', 'en' => 'Ecom Scope Role'],
            'is_active' => true,
        ]);
        $this->testRole->permissions()->attach($this->permission->id, ['scope_type' => ScopeType::Self]);
        $this->scopeUser->roles()->attach($this->testRole->id);
        $this->sameRoleUser->roles()->attach($this->testRole->id);
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();

        // DatabaseTransactions 환경에서 명시적 정리
        if (isset($this->testRole)) {
            $this->testRole->permissions()->detach();
            $this->testRole->delete();
        }
        if (isset($this->permission)) {
            $this->permission->delete();
        }
        Permission::where('identifier', 'like', 'test.%')->delete();

        parent::tearDown();
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Product (owner_key='created_by')
    // ========================================================================

    /**
     * Product — scope=self, 자기가 등록한 상품 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_product(): void
    {
        $this->setupScopePermission('product', 'created_by', ScopeType::Self);
        $product = Product::create([
            'name' => ['ko' => '상품', 'en' => 'Product'],
            'product_code' => 'P-'.uniqid(),
            'created_by' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('product', 'self-own-product', Product::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$product->id}");

        $response->assertStatus(200);
    }

    /**
     * Product — scope=self, 타인이 등록한 상품 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_product(): void
    {
        $this->setupScopePermission('product', 'created_by', ScopeType::Self);
        $product = Product::create([
            'name' => ['ko' => '상품', 'en' => 'Product'],
            'product_code' => 'P-'.uniqid(),
            'created_by' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('product', 'self-deny-product', Product::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$product->id}");

        $response->assertStatus(403);
    }

    /**
     * Product — scope=role, 동일 역할 사용자가 등록한 상품 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_product(): void
    {
        $this->setupScopePermission('product', 'created_by', ScopeType::Role);
        $product = Product::create([
            'name' => ['ko' => '상품', 'en' => 'Product'],
            'product_code' => 'P-'.uniqid(),
            'created_by' => $this->sameRoleUser->id,
        ]);
        $path = $this->registerScopeRoute('product', 'role-product', Product::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$product->id}");

        $response->assertStatus(200);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Order (owner_key='user_id')
    // ========================================================================

    /**
     * Order — scope=self, 자기 주문 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_order(): void
    {
        $this->setupScopePermission('order', 'user_id', ScopeType::Self);
        $order = $this->createOrder($this->scopeUser->id);
        $path = $this->registerScopeRoute('order', 'self-own-order', Order::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$order->id}");

        $response->assertStatus(200);
    }

    /**
     * Order — scope=self, 타인 주문 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_order(): void
    {
        $this->setupScopePermission('order', 'user_id', ScopeType::Self);
        $order = $this->createOrder($this->otherUser->id);
        $path = $this->registerScopeRoute('order', 'self-deny-order', Order::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$order->id}");

        $response->assertStatus(403);
    }

    /**
     * Order — scope=role, 동일 역할 사용자 주문 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_role_allows_access_to_same_role_order(): void
    {
        $this->setupScopePermission('order', 'user_id', ScopeType::Role);
        $order = $this->createOrder($this->sameRoleUser->id);
        $path = $this->registerScopeRoute('order', 'role-order', Order::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$order->id}");

        $response->assertStatus(200);
    }

    /**
     * Order — scope=self, 비회원 주문(user_id=null) 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_guest_order(): void
    {
        $this->setupScopePermission('order', 'user_id', ScopeType::Self);
        $order = $this->createOrder(null);
        $path = $this->registerScopeRoute('order', 'self-deny-guest-order', Order::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$order->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Brand (owner_key='created_by')
    // ========================================================================

    /**
     * Brand — scope=self, 자기가 등록한 브랜드 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_brand(): void
    {
        $this->setupScopePermission('brand', 'created_by', ScopeType::Self);
        $brand = Brand::create([
            'name' => ['ko' => '브랜드', 'en' => 'Brand'],
            'slug' => 'brand-'.uniqid(),
            'created_by' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('brand', 'self-own-brand', Brand::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$brand->id}");

        $response->assertStatus(200);
    }

    /**
     * Brand — scope=self, 타인이 등록한 브랜드 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_brand(): void
    {
        $this->setupScopePermission('brand', 'created_by', ScopeType::Self);
        $brand = Brand::create([
            'name' => ['ko' => '브랜드', 'en' => 'Brand'],
            'slug' => 'brand-'.uniqid(),
            'created_by' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('brand', 'self-deny-brand', Brand::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$brand->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — ShippingPolicy (owner_key='created_by')
    // ========================================================================

    /**
     * ShippingPolicy — scope=self, 자기가 만든 배송정책 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_shipping_policy(): void
    {
        $this->setupScopePermission('shippingPolicy', 'created_by', ScopeType::Self);
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '배송정책', 'en' => 'Shipping Policy'],
            'created_by' => $this->scopeUser->id,
        ]);
        $path = $this->registerScopeRoute('shippingPolicy', 'self-own-shipping', ShippingPolicy::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$policy->id}");

        $response->assertStatus(200);
    }

    /**
     * ShippingPolicy — scope=self, 타인이 만든 배송정책 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_shipping_policy(): void
    {
        $this->setupScopePermission('shippingPolicy', 'created_by', ScopeType::Self);
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '배송정책', 'en' => 'Shipping Policy'],
            'created_by' => $this->otherUser->id,
        ]);
        $path = $this->registerScopeRoute('shippingPolicy', 'self-deny-shipping', ShippingPolicy::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$policy->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // 미들웨어 scope 체크 — Coupon (owner_key='created_by')
    // ========================================================================

    /**
     * Coupon — scope=self, 자기가 만든 쿠폰 접근 시 200 응답이어야 합니다.
     */
    public function test_scope_self_allows_access_to_own_coupon(): void
    {
        $this->setupScopePermission('coupon', 'created_by', ScopeType::Self);
        $coupon = $this->createCoupon($this->scopeUser->id);
        $path = $this->registerScopeRoute('coupon', 'self-own-coupon', Coupon::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$coupon->id}");

        $response->assertStatus(200);
    }

    /**
     * Coupon — scope=self, 타인이 만든 쿠폰 접근 시 403 응답이어야 합니다.
     */
    public function test_scope_self_denies_access_to_other_coupon(): void
    {
        $this->setupScopePermission('coupon', 'created_by', ScopeType::Self);
        $coupon = $this->createCoupon($this->otherUser->id);
        $path = $this->registerScopeRoute('coupon', 'self-deny-coupon', Coupon::class);

        $response = $this->actingAs($this->scopeUser)
            ->getJson("{$path}/{$coupon->id}");

        $response->assertStatus(403);
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Product
    // ========================================================================

    /**
     * Product — scope=null → 전체 상품 조회
     */
    public function test_apply_scope_null_returns_all_products(): void
    {
        $this->createPermissionWithScope('test.products.read', 'product', 'created_by', null);

        Product::create(['name' => ['ko' => '상품1', 'en' => 'P1'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Product::create(['name' => ['ko' => '상품2', 'en' => 'P2'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Product::query();
        PermissionHelper::applyPermissionScope($query, 'test.products.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * Product — scope=self → 자기가 등록한 상품만 조회
     */
    public function test_apply_scope_self_filters_own_products(): void
    {
        $this->createPermissionWithScope('test.products.read', 'product', 'created_by', ScopeType::Self);

        Product::create(['name' => ['ko' => '상품1', 'en' => 'P1'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Product::create(['name' => ['ko' => '상품2', 'en' => 'P2'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Product::query();
        PermissionHelper::applyPermissionScope($query, 'test.products.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * Product — scope=role → 동일 역할 사용자가 등록한 상품만 조회
     */
    public function test_apply_scope_role_filters_same_role_products(): void
    {
        $this->createPermissionWithScope('test.products.read', 'product', 'created_by', ScopeType::Role);

        Product::create(['name' => ['ko' => '상품1', 'en' => 'P1'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Product::create(['name' => ['ko' => '상품2', 'en' => 'P2'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->sameRoleUser->id]);
        Product::create(['name' => ['ko' => '상품3', 'en' => 'P3'], 'product_code' => 'P-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Product::query();
        PermissionHelper::applyPermissionScope($query, 'test.products.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Order
    // ========================================================================

    /**
     * Order — scope=null → 전체 주문 조회
     */
    public function test_apply_scope_null_returns_all_orders(): void
    {
        $this->createPermissionWithScope('test.orders.read', 'order', 'user_id', null);

        $this->createOrder($this->scopeUser->id);
        $this->createOrder($this->otherUser->id);

        $query = Order::query();
        PermissionHelper::applyPermissionScope($query, 'test.orders.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * Order — scope=self → 자기 주문만 조회
     */
    public function test_apply_scope_self_filters_own_orders(): void
    {
        $this->createPermissionWithScope('test.orders.read', 'order', 'user_id', ScopeType::Self);

        $this->createOrder($this->scopeUser->id);
        $this->createOrder($this->otherUser->id);

        $query = Order::query();
        PermissionHelper::applyPermissionScope($query, 'test.orders.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    /**
     * Order — scope=role → 동일 역할 사용자의 주문만 조회
     */
    public function test_apply_scope_role_filters_same_role_orders(): void
    {
        $this->createPermissionWithScope('test.orders.read', 'order', 'user_id', ScopeType::Role);

        $this->createOrder($this->scopeUser->id);
        $this->createOrder($this->sameRoleUser->id);
        $this->createOrder($this->otherUser->id);

        $query = Order::query();
        PermissionHelper::applyPermissionScope($query, 'test.orders.read', $this->scopeUser);

        $this->assertSame(2, $query->count());
    }

    /**
     * Order — scope=self, 비회원 주문(user_id=null) → 제외됨
     */
    public function test_apply_scope_self_excludes_guest_orders(): void
    {
        $this->createPermissionWithScope('test.orders.read', 'order', 'user_id', ScopeType::Self);

        $this->createOrder($this->scopeUser->id);
        $this->createOrder(null); // 비회원 주문

        $query = Order::query();
        PermissionHelper::applyPermissionScope($query, 'test.orders.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Brand
    // ========================================================================

    /**
     * Brand — scope=self → 자기가 등록한 브랜드만 조회
     */
    public function test_apply_scope_self_filters_own_brands(): void
    {
        $this->createPermissionWithScope('test.brands.read', 'brand', 'created_by', ScopeType::Self);

        Brand::create(['name' => ['ko' => '브랜드1', 'en' => 'B1'], 'slug' => 'br-'.uniqid(), 'created_by' => $this->scopeUser->id]);
        Brand::create(['name' => ['ko' => '브랜드2', 'en' => 'B2'], 'slug' => 'br-'.uniqid(), 'created_by' => $this->otherUser->id]);

        $query = Brand::query();
        PermissionHelper::applyPermissionScope($query, 'test.brands.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — ShippingPolicy
    // ========================================================================

    /**
     * ShippingPolicy — scope=self → 자기가 만든 배송정책만 조회
     */
    public function test_apply_scope_self_filters_own_shipping_policies(): void
    {
        $this->createPermissionWithScope('test.shipping.read', 'shippingPolicy', 'created_by', ScopeType::Self);

        ShippingPolicy::create(['name' => ['ko' => '정책1', 'en' => 'SP1'], 'created_by' => $this->scopeUser->id]);
        ShippingPolicy::create(['name' => ['ko' => '정책2', 'en' => 'SP2'], 'created_by' => $this->otherUser->id]);

        $query = ShippingPolicy::query();
        PermissionHelper::applyPermissionScope($query, 'test.shipping.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // applyPermissionScope 목록 필터링 — Coupon
    // ========================================================================

    /**
     * Coupon — scope=self → 자기가 만든 쿠폰만 조회
     */
    public function test_apply_scope_self_filters_own_coupons(): void
    {
        $this->createPermissionWithScope('test.coupons.read', 'coupon', 'created_by', ScopeType::Self);

        $this->createCoupon($this->scopeUser->id);
        $this->createCoupon($this->otherUser->id);

        $query = Coupon::query();
        PermissionHelper::applyPermissionScope($query, 'test.coupons.read', $this->scopeUser);

        $this->assertSame(1, $query->count());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 테스트용 Order 생성 헬퍼
     *
     * @param  int|null  $userId  주문 사용자 ID
     * @return Order 생성된 주문
     */
    private function createOrder(?int $userId): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'order_status' => 'pending_payment',
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'item_count' => 1,
            'ordered_at' => now(),
            'user_id' => $userId,
        ]);
    }

    /**
     * 테스트용 Coupon 생성 헬퍼
     *
     * @param  int  $createdBy  생성자 ID
     * @return Coupon 생성된 쿠폰
     */
    private function createCoupon(int $createdBy): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '쿠폰', 'en' => 'Coupon'],
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'issue_method' => 'auto',
            'issue_condition' => 'signup',
            'issue_status' => 'issuing',
            'target_scope' => 'all',
            'created_by' => $createdBy,
        ]);
    }

    /**
     * 테스트용 권한에 scope 설정 헬퍼
     *
     * @param  string  $routeKey  resource_route_key 값
     * @param  string  $ownerKey  owner_key 값
     * @param  ScopeType|null  $scopeType  scope_type 값
     * @return void
     */
    private function setupScopePermission(string $routeKey, string $ownerKey, ?ScopeType $scopeType): void
    {
        $this->permission->update([
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->syncWithoutDetaching([
            $this->permission->id => ['scope_type' => $scopeType],
        ]);

        $this->clearPermissionCache();
    }

    /**
     * 별도 권한 생성 및 역할에 할당하는 헬퍼
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $routeKey  resource_route_key
     * @param  string|null  $ownerKey  owner_key
     * @param  ScopeType|null  $scopeType  scope_type
     * @return Permission 생성된 권한
     */
    private function createPermissionWithScope(string $identifier, ?string $routeKey, ?string $ownerKey, ?ScopeType $scopeType): Permission
    {
        $perm = Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'type' => PermissionType::Admin,
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);

        $this->testRole->permissions()->attach($perm->id, ['scope_type' => $scopeType]);
        $this->clearPermissionCache();

        return $perm;
    }

    /**
     * 모델 바인딩 포함 테스트 라우트 등록 헬퍼
     *
     * @param  string  $routeKey  라우트 파라미터명
     * @param  string  $suffix  라우트 경로 구분용 접미사
     * @param  string  $modelClass  바인딩할 모델 FQCN
     * @return string 등록된 라우트 경로 (파라미터 제외)
     */
    private function registerScopeRoute(string $routeKey, string $suffix, string $modelClass): string
    {
        Route::bind($routeKey, fn ($value) => $modelClass::findOrFail($value));

        $path = "/api/test-ecom-scope-{$suffix}";
        Route::middleware(['api', 'auth:sanctum', 'permission:admin,test.ecommerce.scope'])
            ->get("{$path}/{{$routeKey}}", fn (Model $model) => response()->json(['message' => 'OK']));

        return $path;
    }

    /**
     * PermissionHelper static 캐시 초기화
     *
     * @return void
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
