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
                    'in_stock' => true,
                ],
            ],
        ];

        $schema = $this->module->seoStructuredData('product', $context);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('에어맥스', $schema['name']);
        $this->assertSame('한국어 설명', $schema['description']);
        $this->assertSame('129000', $schema['offers']['price']);
    }
}
