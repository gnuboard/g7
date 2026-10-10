<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

use App\Http\Middleware\PermissionMiddleware;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 코드 라우트 제약 테스트
 *
 * 등록 검증(StoreProductRequest::PRODUCT_CODE_PATTERN)은 상품 코드에 하이픈(-)·밑줄(_)을 허용하는데,
 * 공개·관리자 라우트의 {identifier} 제약이 영숫자만 받아 그런 상품의 상세·수정 화면이 404 였다.
 * 라우트 제약이 등록 규칙과 같은 문자를 받는지 검증한다.
 */
class ProductCodeRouteConstraintTest extends ModuleTestCase
{
    /**
     * 공개 상품 API 가 비로그인 손님 역할로 열리도록 권한 미들웨어의 손님 역할 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * 상품코드에 허용된 하이픈(-)·밑줄(_)이 들어간 상품도 상세·리뷰·문의·다운로드 쿠폰을 조회할 수 있다.
     *
     * @scenario case=route_lookup
     *
     * @effects hyphen_underscore_code_resolves_public_routes
     *
     * @return void 반환값 없음
     */
    public function test_show_and_sub_resources_accept_hyphen_and_underscore_product_code(): void
    {
        $product = Product::factory()->onSale()->create(['product_code' => 'AB-12_cd']);
        $base = '/api/modules/sirsoft-ecommerce/products/AB-12_cd';

        $this->getJson($base)->assertStatus(200)->assertJsonPath('data.id', $product->id);
        $this->getJson($base.'/reviews')->assertStatus(200);
        $this->getJson($base.'/inquiries')->assertStatus(200);
        $this->getJson($base.'/downloadable-coupons')->assertStatus(200);
    }

    /**
     * 하이픈(-)·밑줄(_)이 든 product_code 로 관리자 상세·수정 폼·복사·삭제 가능 여부·활동 로그를 조회할 수 있다.
     *
     * @scenario case=route_lookup
     *
     * @effects hyphen_underscore_code_resolves_admin_routes
     *
     * @return void 반환값 없음
     */
    public function test_product_routes_accept_hyphen_and_underscore_product_code(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read', 'sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create(['product_code' => 'AB-12_cd']);
        $base = '/api/modules/sirsoft-ecommerce/admin/products/AB-12_cd';

        $this->actingAs($user)->getJson($base)->assertOk()->assertJsonPath('data.id', $product->id);
        $this->actingAs($user)->getJson($base.'/form')->assertOk();
        $this->actingAs($user)->getJson($base.'/copy')->assertOk();
        $this->actingAs($user)->getJson($base.'/can-delete')->assertOk();
        $this->actingAs($user)->getJson($base.'/logs')->assertOk();
    }
}
