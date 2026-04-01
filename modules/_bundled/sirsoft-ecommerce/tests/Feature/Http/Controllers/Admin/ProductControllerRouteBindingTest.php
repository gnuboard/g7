<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductController 라우트 바인딩 테스트
 *
 * 숫자 ID와 product_code(영숫자) 모두로 상품 관련 API에
 * 접근할 수 있는지 검증합니다.
 */
class ProductControllerRouteBindingTest extends ModuleTestCase
{
    // ========================================
    // GET /products/{identifier} - show
    // ========================================

    /**
     * 숫자 ID로 상품 상세 조회 가능
     */
    public function test_show_product_by_numeric_id(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: 숫자 ID로 상품 조회
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}");

        // Then: 성공
        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    /**
     * product_code(영숫자)로 상품 상세 조회 가능
     */
    public function test_show_product_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'product_code' => 'NQK9D6NKULB4UN7D',
        ]);

        // When: product_code로 상품 조회
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/NQK9D6NKULB4UN7D');

        // Then: 성공
        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    /**
     * 존재하지 않는 identifier로 상품 조회 시 404
     */
    public function test_show_product_with_invalid_identifier_returns_not_found(): void
    {
        // Given: 관리자
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        // When: 존재하지 않는 코드로 조회
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/NONEXISTENT999');

        // Then: 404
        $response->assertNotFound();
    }

    // ========================================
    // PUT /products/{product} - update (route model binding)
    // ========================================

    /**
     * product_code로 상품 수정 가능 (route model binding)
     */
    public function test_update_product_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.update']);
        $product = Product::factory()->create([
            'product_code' => 'ABCD1234EFGH5678',
        ]);

        // When: product_code로 상품 수정 요청
        $response = $this->actingAs($user)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/products/ABCD1234EFGH5678', [
                'name' => ['ko' => '수정된 상품명'],
                'selling_price' => 20000,
            ]);

        // Then: 404가 아닌 응답 (유효성 검증 실패는 422로 올 수 있지만 405/404는 아님)
        $this->assertNotEquals(404, $response->status(), 'product_code로 라우트 매칭에 실패해서는 안 됩니다');
        $this->assertNotEquals(405, $response->status(), 'PUT 메서드가 지원되어야 합니다');
    }

    /**
     * 숫자 ID로 상품 수정 가능 (route model binding)
     */
    public function test_update_product_by_numeric_id(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.update']);
        $product = Product::factory()->create();

        // When: 숫자 ID로 상품 수정 요청
        $response = $this->actingAs($user)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", [
                'name' => ['ko' => '수정된 상품명'],
                'selling_price' => 20000,
            ]);

        // Then: 404/405가 아닌 응답
        $this->assertNotEquals(404, $response->status(), '숫자 ID로 라우트 매칭에 실패해서는 안 됩니다');
        $this->assertNotEquals(405, $response->status(), 'PUT 메서드가 지원되어야 합니다');
    }

    // ========================================
    // DELETE /products/{product} - destroy (route model binding)
    // ========================================

    /**
     * product_code로 상품 삭제 가능 (route model binding)
     */
    public function test_delete_product_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create([
            'product_code' => 'DEL1CODE2TEST34',
        ]);

        // When: product_code로 상품 삭제 요청
        $response = $this->actingAs($user)
            ->deleteJson('/api/modules/sirsoft-ecommerce/admin/products/DEL1CODE2TEST34');

        // Then: 404/405가 아닌 응답
        $this->assertNotEquals(404, $response->status(), 'product_code로 라우트 매칭에 실패해서는 안 됩니다');
        $this->assertNotEquals(405, $response->status(), 'DELETE 메서드가 지원되어야 합니다');
    }

    // ========================================
    // GET /products/{product}/logs (ID 또는 product_code)
    // ========================================

    /**
     * 숫자 ID로 상품 로그 조회 가능
     */
    public function test_product_logs_by_numeric_id(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: 숫자 ID로 로그 조회
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/logs");

        // Then: 성공
        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    /**
     * product_code(영숫자)로 상품 로그 조회 가능
     */
    public function test_product_logs_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'product_code' => 'LOGS1CODE2TEST34',
        ]);

        // When: product_code로 로그 조회
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/LOGS1CODE2TEST34/logs');

        // Then: 성공
        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    /**
     * 존재하지 않는 identifier로 로그 조회 시 404
     */
    public function test_product_logs_with_invalid_id_returns_not_found(): void
    {
        // Given: 관리자
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        // When: 존재하지 않는 ID로 로그 조회
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/999999/logs');

        // Then: 404
        $response->assertNotFound();
    }

    // ========================================
    // GET /products/{product}/form (ID 또는 product_code)
    // ========================================

    /**
     * product_code(영숫자)로 상품 폼 데이터 조회 가능
     */
    public function test_product_form_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'product_code' => 'FORM1CODE2TEST34',
        ]);

        // When: product_code로 폼 데이터 조회
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/FORM1CODE2TEST34/form');

        // Then: 404가 아닌 응답 (라우트 매칭 성공)
        $this->assertNotEquals(404, $response->status(), 'product_code로 /form 라우트 매칭에 실패해서는 안 됩니다');
    }

    // ========================================
    // GET /products/{product}/can-delete (ID 또는 product_code)
    // ========================================

    /**
     * product_code(영숫자)로 상품 삭제 가능 여부 확인 가능
     */
    public function test_product_can_delete_by_product_code(): void
    {
        // Given: 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create([
            'product_code' => 'CDEL1CODE2TEST34',
        ]);

        // When: product_code로 삭제 가능 여부 확인
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/CDEL1CODE2TEST34/can-delete');

        // Then: 404가 아닌 응답 (라우트 매칭 성공)
        $this->assertNotEquals(404, $response->status(), 'product_code로 /can-delete 라우트 매칭에 실패해서는 안 됩니다');
    }

    // ========================================
    // resolveRouteBinding 검증
    // ========================================

    /**
     * Product 모델의 resolveRouteBinding이 숫자 ID를 올바르게 처리
     */
    public function test_resolve_route_binding_with_numeric_id(): void
    {
        // Given: 상품
        $product = Product::factory()->create();

        // When: resolveRouteBinding 호출
        $resolved = (new Product())->resolveRouteBinding($product->id);

        // Then: 올바른 상품 반환
        $this->assertNotNull($resolved);
        $this->assertEquals($product->id, $resolved->id);
    }

    /**
     * Product 모델의 resolveRouteBinding이 product_code를 올바르게 처리
     */
    public function test_resolve_route_binding_with_product_code(): void
    {
        // Given: 상품
        $product = Product::factory()->create([
            'product_code' => 'BINDING1TEST234',
        ]);

        // When: resolveRouteBinding 호출
        $resolved = (new Product())->resolveRouteBinding('BINDING1TEST234');

        // Then: 올바른 상품 반환
        $this->assertNotNull($resolved);
        $this->assertEquals($product->id, $resolved->id);
        $this->assertEquals('BINDING1TEST234', $resolved->product_code);
    }

    /**
     * Product 모델의 resolveRouteBinding이 존재하지 않는 값에 대해 예외 발생
     */
    public function test_resolve_route_binding_throws_for_nonexistent(): void
    {
        // When & Then: 존재하지 않는 값으로 resolveRouteBinding 호출 시 예외 발생
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new Product())->resolveRouteBinding('NONEXISTENT_CODE');
    }
}
