<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductResource 의 seo_sync_* 노출 회귀 테스트 (A26)
 *
 * EDIT 폼은 product 데이터소스(show → ProductResource)로 SEO 동기화 플래그를 받는다.
 * ProductResource 에 seo_sync_title / seo_sync_description 이 없으면, 동기화 OFF 로
 * 저장한 뒤 폼을 다시 열었을 때 토글이 기본값(ON)으로 되돌아가 표시된다(영속 상태 미복원).
 */
class ProductResourceSeoSyncTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
    }

    /**
     * ProductResource 가 seo_sync_title / seo_sync_description 을 노출한다.
     */
    public function test_resource_exposes_seo_sync_flags(): void
    {
        $product = Product::factory()->create([
            'seo_sync_title' => false,
            'seo_sync_description' => true,
            'meta_title' => '커스텀 SEO 제목',
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);
        $array = (new ProductResource($loaded))->toArray(Request::create('/'));

        $this->assertArrayHasKey('seo_sync_title', $array, 'ProductResource 에 seo_sync_title 키가 없습니다.');
        $this->assertArrayHasKey('seo_sync_description', $array, 'ProductResource 에 seo_sync_description 키가 없습니다.');
        $this->assertFalse($array['seo_sync_title'], 'seo_sync_title=false 가 그대로 노출되어야 합니다.');
        $this->assertTrue($array['seo_sync_description'], 'seo_sync_description=true 가 그대로 노출되어야 합니다.');
    }

    /**
     * boolean 캐스팅이 적용되어 정수가 아닌 boolean 으로 노출된다.
     */
    public function test_resource_seo_sync_flags_are_boolean(): void
    {
        $product = Product::factory()->create([
            'seo_sync_title' => true,
            'seo_sync_description' => false,
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);
        $array = (new ProductResource($loaded))->toArray(Request::create('/'));

        $this->assertIsBool($array['seo_sync_title']);
        $this->assertIsBool($array['seo_sync_description']);
        $this->assertTrue($array['seo_sync_title']);
        $this->assertFalse($array['seo_sync_description']);
    }
}
