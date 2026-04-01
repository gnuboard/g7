<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * WishlistController Feature 테스트
 *
 * 찜(위시리스트) API 엔드포인트의 FormRequest 검증 및 응답을 테스트합니다.
 */
class WishlistControllerTest extends ModuleTestCase
{
    /**
     * 테스트용 배송정책을 생성합니다.
     *
     * @return ShippingPolicy
     */
    protected function createShippingPolicy(): ShippingPolicy
    {
        return ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'shipping_method' => ShippingMethodEnum::PARCEL,
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * 테스트용 상품을 생성합니다.
     *
     * @return Product
     */
    protected function createProduct(): Product
    {
        $shippingPolicy = $this->createShippingPolicy();

        return ProductFactory::new()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);
    }

    // ========================================
    // 찜 토글 테스트
    // ========================================

    /**
     * 인증된 사용자가 찜 토글 시 상품이 추가됩니다.
     */
    public function test_toggle_wishlist_adds_product(): void
    {
        // Given: 인증된 사용자와 상품
        $user = $this->createUser();
        $product = $this->createProduct();

        // When: 찜 토글 요청
        $response = $this->actingAs($user)->postJson('/api/modules/sirsoft-ecommerce/wishlist/toggle', [
            'product_id' => $product->id,
        ]);

        // Then: 성공 응답 + added = true
        $response->assertOk();
        $response->assertJsonPath('data.added', true);
        $this->assertDatabaseHas('ecommerce_product_wishlists', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * 이미 찜한 상품을 다시 토글하면 제거됩니다.
     */
    public function test_toggle_wishlist_removes_product_when_already_wishlisted(): void
    {
        // Given: 이미 찜한 상태
        $user = $this->createUser();
        $product = $this->createProduct();
        ProductWishlist::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        // When: 찜 토글 요청
        $response = $this->actingAs($user)->postJson('/api/modules/sirsoft-ecommerce/wishlist/toggle', [
            'product_id' => $product->id,
        ]);

        // Then: 성공 응답 + added = false
        $response->assertOk();
        $response->assertJsonPath('data.added', false);
        $this->assertDatabaseMissing('ecommerce_product_wishlists', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * 비인증 사용자가 찜 토글 시 401을 반환합니다.
     */
    public function test_toggle_wishlist_returns_401_for_unauthenticated_user(): void
    {
        // Given: 상품만 존재
        $product = $this->createProduct();

        // When: 비인증 상태로 요청
        $response = $this->postJson('/api/modules/sirsoft-ecommerce/wishlist/toggle', [
            'product_id' => $product->id,
        ]);

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    /**
     * 존재하지 않는 상품 ID로 찜 토글 시 422를 반환합니다.
     */
    public function test_toggle_wishlist_returns_422_for_non_existent_product(): void
    {
        // Given: 인증된 사용자
        $user = $this->createUser();

        // When: 존재하지 않는 상품 ID로 요청
        $response = $this->actingAs($user)->postJson('/api/modules/sirsoft-ecommerce/wishlist/toggle', [
            'product_id' => 99999,
        ]);

        // Then: 422 Validation Error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['product_id']);
    }

    // ========================================
    // 찜 목록 조회 테스트
    // ========================================

    /**
     * 인증된 사용자의 찜 목록을 페이지네이션으로 조회합니다.
     */
    public function test_index_returns_paginated_wishlist(): void
    {
        // Given: 사용자가 2개 상품을 찜한 상태
        $user = $this->createUser();
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();
        ProductWishlist::create(['user_id' => $user->id, 'product_id' => $product1->id]);
        ProductWishlist::create(['user_id' => $user->id, 'product_id' => $product2->id]);

        // When: 찜 목록 조회
        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-ecommerce/wishlist');

        // Then: 성공 응답 + 2개 항목
        $response->assertOk();
        $response->assertJsonPath('data.pagination.total', 2);
        $response->assertJsonStructure([
            'data' => [
                'data',
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
    }

    /**
     * 비인증 사용자가 찜 목록 조회 시 401을 반환합니다.
     */
    public function test_index_returns_401_for_unauthenticated_user(): void
    {
        // When: 비인증 상태로 조회
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/wishlist');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    // ========================================
    // 찜 삭제 테스트
    // ========================================

    /**
     * 자신의 찜을 삭제할 수 있습니다.
     */
    public function test_destroy_removes_own_wishlist_item(): void
    {
        // Given: 찜한 상태
        $user = $this->createUser();
        $product = $this->createProduct();
        $wishlist = ProductWishlist::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        // When: 삭제 요청
        $response = $this->actingAs($user)->deleteJson("/api/modules/sirsoft-ecommerce/wishlist/{$wishlist->id}");

        // Then: 성공 응답 + DB에서 제거
        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_product_wishlists', [
            'id' => $wishlist->id,
        ]);
    }

    /**
     * 다른 사용자의 찜을 삭제 시도하면 404를 반환합니다.
     */
    public function test_destroy_returns_404_for_other_users_wishlist(): void
    {
        // Given: 다른 사용자의 찜
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $product = $this->createProduct();
        $wishlist = ProductWishlist::create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);

        // When: 다른 사용자가 삭제 시도
        $response = $this->actingAs($otherUser)->deleteJson("/api/modules/sirsoft-ecommerce/wishlist/{$wishlist->id}");

        // Then: 404 Not Found
        $response->assertStatus(404);
        $this->assertDatabaseHas('ecommerce_product_wishlists', [
            'id' => $wishlist->id,
        ]);
    }
}
