<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품코드의 하이픈·밑줄로 관리자 상품 API 에 접근하는지 검증합니다.
 *
 * 상품코드 규칙(`StoreProductRequest::PRODUCT_CODE_PATTERN` = `^[A-Za-z0-9_-]+$`)은 하이픈·밑줄을 받는데
 * 라우트 제약은 영숫자(`[0-9a-zA-Z]+`)만 받아, 그런 코드의 상품은 수정 화면이 상세 조회 405·활동 로그 404 로
 * 비었다(#736 S2 E2E 실측 — `CBT-TEST-…`).
 */
class ProductRouteCodeCharactersTest extends ModuleTestCase
{
    /**
     * 하이픈·밑줄이 든 상품코드로 상세·폼·복사·삭제 가능 여부·활동 로그를 모두 연다.
     *
     * @scenario code_chars=hyphen_underscore, endpoint=read_routes
     * @effects code_with_hyphen_reaches_every_product_read_route
     *
     * @return void 반환값 없음 (단언으로 검증)
     */
    public function test_product_code_with_hyphen_and_underscore_reaches_read_routes(): void
    {
        $user = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.delete',
        ]);
        $product = Product::factory()->create(['product_code' => 'CBT-TEST_2026-01']);
        $base = '/api/modules/sirsoft-ecommerce/admin/products/CBT-TEST_2026-01';

        $show = $this->actingAs($user)->getJson($base);
        $show->assertOk();
        $this->assertSame($product->id, $show->json('data.id'));

        foreach (['/form', '/copy', '/can-delete', '/logs'] as $suffix) {
            $status = $this->actingAs($user)->getJson($base.$suffix)->status();
            $this->assertNotContains($status, [404, 405], "{$suffix} 가 하이픈·밑줄 상품코드로 라우트에 닿지 못했다 ({$status})");
        }
    }

    /**
     * 리터럴 경로(GET options · POST generate-code)는 넓힌 제약에도 상세·수정 라우트로 삼켜지지 않는다.
     *
     * @scenario code_chars=hyphen_underscore, endpoint=literal_routes
     * @effects literal_routes_not_swallowed_by_identifier
     *
     * @return void 반환값 없음 (단언으로 검증)
     */
    public function test_literal_routes_are_not_swallowed_by_the_identifier_route(): void
    {
        $routes = app('router')->getRoutes();
        $base = '/api/modules/sirsoft-ecommerce/admin/products/';

        $this->assertStringEndsWith('admin.products.options', (string) $routes->match(Request::create($base.'options', 'GET'))->getName());
        $this->assertStringEndsWith('admin.products.generate-code', (string) $routes->match(Request::create($base.'generate-code', 'POST'))->getName());
    }

    /**
     * 공개 상품 라우트(상세·쿠폰·리뷰·문의)도 하이픈·밑줄 상품코드를 자기 라우트로 받는다(종전 영숫자 제약으로 404).
     *
     * @scenario code_chars=hyphen_underscore, endpoint=public_routes
     * @effects public_product_routes_accept_code_with_hyphen
     *
     * @return void 반환값 없음 (단언으로 검증)
     */
    public function test_public_product_routes_accept_code_with_hyphen_and_underscore(): void
    {
        $routes = app('router')->getRoutes();
        $base = '/api/modules/sirsoft-ecommerce/products/CBT-TEST_2026-01';

        $this->assertStringEndsWith('products.show', (string) $routes->match(Request::create($base, 'GET'))->getName());
        foreach (['/downloadable-coupons' => 'products.downloadable-coupons', '/reviews' => 'products.reviews.index', '/inquiries' => 'products.inquiries.index'] as $suffix => $name) {
            $this->assertStringEndsWith($name, (string) $routes->match(Request::create($base.$suffix, 'GET'))->getName());
        }
        $this->assertStringEndsWith('products.inquiries.store', (string) $routes->match(Request::create($base.'/inquiries', 'POST'))->getName());
        // 리터럴(popular·new·recent)은 와일드카드보다 먼저 선언돼 그대로 자기 라우트로 간다
        $this->assertStringEndsWith('products.recent', (string) $routes->match(Request::create('/api/modules/sirsoft-ecommerce/products/recent', 'GET'))->getName());
    }
}
