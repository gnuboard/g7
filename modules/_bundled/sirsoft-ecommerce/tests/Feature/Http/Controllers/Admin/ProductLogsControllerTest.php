<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 활동 로그 조회 엔드포인트 테스트
 *
 * 조회 조건(페이지 크기·정렬)이 FormRequest 에서 검증되는지, 로그 합산 조회가 Repository
 * 위임으로 정상 동작하는지 확인합니다. 이전에는 컨트롤러가 요청 값을 검증 없이 그대로 쿼리에
 * 넘겨 `per_page=99999` 같은 값으로 한 번에 전량을 끌어올 수 있었습니다.
 */
class ProductLogsControllerTest extends ModuleTestCase
{
    /**
     * 로그 조회 URL 을 만듭니다.
     *
     * @param  Product  $product  대상 상품
     * @param  string  $query  쿼리스트링 (물음표 제외)
     * @return string 엔드포인트 URL
     */
    private function url(Product $product, string $query = ''): string
    {
        $url = "/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/logs";

        return $query === '' ? $url : "{$url}?{$query}";
    }

    /**
     * 기본 조회가 정상 응답한다
     */
    public function test_logs_returns_paginated_response(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        $this->actingAs($user)
            ->getJson($this->url($product))
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'meta']]);
    }

    /**
     * 상한을 넘는 per_page 는 거절된다
     */
    public function test_logs_rejects_per_page_over_limit(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        $this->actingAs($user)
            ->getJson($this->url($product, 'per_page=99999'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * 허용되지 않은 정렬 방향은 거절된다
     */
    public function test_logs_rejects_unknown_sort_order(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        $this->actingAs($user)
            ->getJson($this->url($product, 'sort_order=sideways'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_order']);
    }
}
