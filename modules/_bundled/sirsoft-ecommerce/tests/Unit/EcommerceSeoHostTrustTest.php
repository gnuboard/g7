<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Extension\ModuleManager;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ForgesRequestHost;

/**
 * 상품·카테고리 검색엔진·SNS 미리보기 이미지 주소가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 봇 화면은 캐시되어(키는 사이트 주소 기준) 이후 모든 크롤러·SNS 미리보기에 그대로 나간다.
 */
class EcommerceSeoHostTrustTest extends ModuleTestCase
{
    use ForgesRequestHost;

    private Module $module;

    /**
     * 사이트 주소를 고정하고 모듈 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
        $this->module = app(ModuleManager::class)->getModule('sirsoft-ecommerce')
            ?? new Module(base_path('modules/sirsoft-ecommerce'));
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 상품·카테고리의 상대 경로 이미지는 사이트 주소로 절대화된다.
     *
     * @scenario host_header=forged, sink=seo_image
     *
     * @effects seo_image_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_product_and_category_seo_image_use_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/shop/products/1');
        $product = ['product' => ['data' => [
            'name' => '위조 Host 회귀',
            'thumbnail_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
        ]]];
        $category = ['category' => ['data' => [
            'name' => '카테고리',
            'thumbnail_url' => '/api/modules/sirsoft-ecommerce/category-image/cat123',
        ]]];
        $productImage = self::forgedHostSite().'/api/modules/sirsoft-ecommerce/product-image/abc123';

        $this->assertSame($productImage, $this->module->seoOgDefaults('product', $product)['image'] ?? null);
        $this->assertSame(
            self::forgedHostSite().'/api/modules/sirsoft-ecommerce/category-image/cat123',
            $this->module->seoOgDefaults('category', $category)['image'] ?? null,
        );

        $schema = $this->module->seoStructuredData('product', $product);
        $image = $schema['image'] ?? null;
        $this->assertSame($productImage, is_array($image) ? ($image[0] ?? null) : $image);
    }
}
