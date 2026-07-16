<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * EcommerceModule SEO declaration 회귀 테스트.
 *
 * 회귀: 상품 다국어 컬럼이 MariaDB 환경에서 array 로 전달될 때
 * "Array to string conversion" → SeoMiddleware catch → SPA fallback (모든 봇 미리보기 미출력).
 */
class EcommerceModuleSeoTest extends ModuleTestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = app(\App\Extension\ModuleManager::class)->getModule('sirsoft-ecommerce')
            ?? new Module(base_path('modules/sirsoft-ecommerce'));
    }

    /**
     * 회귀: 상품 name/description 이 다국어 JSON array 일 때 throw 없이 정상 추출.
     */
    public function test_product_seo_og_defaults_handles_multilingual_array(): void
    {
        app()->setLocale('ko');

        $context = [
            'product' => [
                'data' => [
                    'name' => ['ko' => '에어맥스', 'en' => 'AirMax'],
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'selling_price' => 129000,
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('product', $context);

        $this->assertSame('에어맥스', $og['image_alt']);
        $this->assertSame('https://example.com/p.jpg', $og['image']);
    }

    /**
     * 회귀: thumbnail_url 이 상대 경로(/api/...) 일 때 og:image 가 절대 URL 로 변환.
     *
     * 슬랙·페이스북·쓰레드 모두 og:image 는 절대 URL 필수. 상대 경로 출력 시 이미지 미표시.
     */
    public function test_product_seo_og_defaults_image_is_absolute_url(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '가죽 크로스백',
                    'thumbnail_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('product', $context);

        $this->assertArrayHasKey('image', $og);
        $this->assertStringStartsWith('http', $og['image'], 'og:image 는 절대 URL 이어야 페이스북·슬랙·쓰레드가 인식합니다');
        $this->assertStringContainsString('/api/modules/sirsoft-ecommerce/product-image/abc123', $og['image']);
    }

    /**
     * 회귀: 상품 structured_data 도 다국어 array 안전 처리.
     */
    public function test_product_structured_data_handles_multilingual_array(): void
    {
        app()->setLocale('ko');

        $context = [
            'product' => [
                'data' => [
                    'name' => ['ko' => '에어맥스', 'en' => 'AirMax'],
                    'description' => ['ko' => '한국어 설명', 'en' => 'English description'],
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'selling_price' => 129000,
                    'sales_status' => 'on_sale',
                ],
            ],
        ];

        $schema = $this->module->seoStructuredData('product', $context);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('에어맥스', $schema['name']);
        $this->assertSame('한국어 설명', $schema['description']);
        $this->assertSame('129000', $schema['offers']['price']);
    }

    /**
     * seoOgDefaultMeta 가 데이터 경로(연결 칩) + 다국어 라벨을 선언한다.
     */
    public function test_product_seo_og_default_meta_declares_data_path_and_label(): void
    {
        $meta = $this->module->seoOgDefaultMeta('product');

        // label 은 번역 키(언어팩 대응) — __() 로 해석돼 한국어로 표시된다.
        $this->assertSame('{{product.data.thumbnail_url}}', $meta['image']['expr']);
        $this->assertSame('sirsoft-ecommerce::seo.auto_value.product_image', $meta['image']['label']);
        $this->assertSame('상품 대표 이미지', __($meta['image']['label'], [], 'ko'));
        $this->assertSame('Product image', __($meta['image']['label'], [], 'en'));
        $this->assertSame('{{product.data.name}}', $meta['image_alt']['expr']);

        // category 도 자기 데이터 경로 선언.
        $catMeta = $this->module->seoOgDefaultMeta('category');
        $this->assertSame('{{category.data.thumbnail_url}}', $catMeta['image']['expr']);

        // 도메인 외 page_type 은 빈 배열(평문 폴백).
        $this->assertSame([], $this->module->seoOgDefaultMeta('shop_index'));
    }

    /**
     * seoStructuredDataMeta 가 점 경로 키별 데이터 경로를 선언한다.
     */
    public function test_product_structured_data_meta_declares_dotted_paths(): void
    {
        $meta = $this->module->seoStructuredDataMeta('product');

        $this->assertSame('{{product.data.name}}', $meta['name']['expr']);
        $this->assertSame('{{product.data.selling_price}}', $meta['offers.price']['expr']);
        $this->assertSame('{{product.data.sku}}', $meta['sku']['expr']);
    }

    /**
     * 정합성: 메타가 선언한 키는 운영 declaration 이 산출 가능한 키여야 한다(키 드리프트 차단).
     * og.image/image_alt 는 declaration 키 집합에 존재해야 하고, structured 점 경로는 평탄화한
     * declaration 키에 존재해야 한다(파생값 제외는 허용 — 메타 ⊆ declaration).
     */
    public function test_meta_keys_are_subset_of_declaration_keys(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '키정합 상품',
                    'short_description' => '설명',
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'sku' => 'SKU-1',
                    'selling_price' => 1000,
                ],
            ],
        ];

        $ogKeys = array_keys($this->module->seoOgDefaults('product', $context));
        foreach (array_keys($this->module->seoOgDefaultMeta('product')) as $metaKey) {
            $this->assertContains($metaKey, $ogKeys, "og 메타 키 '{$metaKey}' 는 declaration 키여야 합니다");
        }

        $flatStructured = $this->flattenKeys($this->module->seoStructuredData('product', $context));
        foreach (array_keys($this->module->seoStructuredDataMeta('product')) as $metaKey) {
            $this->assertContains($metaKey, $flatStructured, "structured 메타 키 '{$metaKey}' 는 declaration 평탄 키여야 합니다");
        }
    }

    /**
     * 결함(라이브 검수 2026-06-14): structured offers.availability 가 존재하지 않는 'in_stock' 키를
     * 참조해 **모든 상품이 OutOfStock** 으로 출력됨. ProductResource 실제 키는 sales_status(enum value)
     * 와 stock_quantity. sales_status='on_sale' → InStock 이어야 한다.
     */
    public function test_product_structured_availability_uses_sales_status_not_missing_in_stock_key(): void
    {
        $base = [
            'name' => '재고 상품',
            'selling_price' => 1000,
        ];

        // 판매중(on_sale) → InStock.
        $onSale = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'on_sale', 'stock_quantity' => 320]]]);
        $this->assertSame('https://schema.org/InStock', $onSale['offers']['availability'], '판매중 상품은 InStock');

        // 품절(sold_out) → OutOfStock.
        $soldOut = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'sold_out', 'stock_quantity' => 0]]]);
        $this->assertSame('https://schema.org/OutOfStock', $soldOut['offers']['availability'], '품절 상품은 OutOfStock');

        // 판매중지(suspended) → OutOfStock.
        $suspended = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'suspended']]]);
        $this->assertSame('https://schema.org/OutOfStock', $suspended['offers']['availability'], '판매중지 상품은 OutOfStock');
    }

    /** 중첩 배열을 점 경로 키 목록으로 평탄화(@type/@context 제외). */
    private function flattenKeys(array $block, string $prefix = ''): array
    {
        $out = [];
        foreach ($block as $k => $v) {
            if ($k === '@type' || $k === '@context') {
                continue;
            }
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v) && ! array_is_list($v)) {
                $out = array_merge($out, $this->flattenKeys($v, $path));
            } else {
                $out[] = $path;
            }
        }

        return $out;
    }
}
