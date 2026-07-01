<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductResource 의 additional_options 노출 회귀 테스트 (§B-FAIL-2)
 *
 * EDIT 폼은 product 데이터소스(show → ProductResource)로 additional_options 를 받는다.
 * ProductResource 에 키가 없으면 보유 상품이 EDIT 시 "미사용"으로 표시되고 저장 시 소실된다.
 * getDetail(findWithOptions) 가 additionalOptions 를 eager load 하고,
 * ProductResource 가 {id,name,is_required} 형식으로 노출하는지 검증한다.
 */
class ProductResourceAdditionalOptionsTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
    }

    /**
     * getDetail 이 additionalOptions 를 eager load 한다.
     */
    public function test_get_detail_eager_loads_additional_options(): void
    {
        $product = Product::factory()->create();
        ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);

        $this->assertTrue($loaded->relationLoaded('additionalOptions'));
        $this->assertCount(1, $loaded->additionalOptions);
    }

    /**
     * ProductResource 가 additional_options 를 {id,name,is_required} 형식으로 노출한다.
     */
    public function test_resource_exposes_additional_options(): void
    {
        $product = Product::factory()->create();
        $opt = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);
        $array = (new ProductResource($loaded))->toArray(Request::create('/'));

        $this->assertArrayHasKey('additional_options', $array);
        $this->assertCount(1, $array['additional_options']);
        $this->assertSame($opt->id, $array['additional_options'][0]['id']);
        $this->assertSame(['ko' => '각인 문구', 'en' => 'Engraving'], $array['additional_options'][0]['name']);
        $this->assertTrue($array['additional_options'][0]['is_required']);
    }

    /**
     * 추가옵션이 없으면 빈 배열을 노출한다 (null 아님).
     */
    public function test_resource_exposes_empty_array_when_no_additional_options(): void
    {
        $product = Product::factory()->create();
        $loaded = $this->service->getDetail($product->id, includeInactive: true);
        $array = (new ProductResource($loaded))->toArray(Request::create('/'));

        $this->assertArrayHasKey('additional_options', $array);
        $this->assertSame([], $array['additional_options']);
    }

    /**
     * getDetail 이 추가옵션의 선택지(values)까지 eager load 한다 (회귀: 데이터 손실).
     *
     * findWithOptions 가 additionalOptions 만 로드하고 values 를 누락하면
     * EDIT 폼이 기존 선택지를 0개로 표시하고 저장 시 선택지가 전멸한다.
     */
    public function test_get_detail_eager_loads_additional_option_values(): void
    {
        $product = Product::factory()->create();
        $opt = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);
        ProductAdditionalOptionValue::create([
            'additional_option_id' => $opt->id,
            'name' => ['ko' => '없음', 'en' => 'None'],
            'price_adjustment' => 0,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        ProductAdditionalOptionValue::create([
            'additional_option_id' => $opt->id,
            'name' => ['ko' => '각인 추가', 'en' => 'Engrave'],
            'price_adjustment' => 5000,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);

        $this->assertTrue($loaded->additionalOptions[0]->relationLoaded('values'));
        $this->assertCount(2, $loaded->additionalOptions[0]->values);
    }

    /**
     * ProductResource 가 추가옵션 선택지(values)를 round-trip 형식으로 노출한다 (회귀: 데이터 손실).
     */
    public function test_resource_exposes_additional_option_values(): void
    {
        $product = Product::factory()->create();
        $opt = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $value = ProductAdditionalOptionValue::create([
            'additional_option_id' => $opt->id,
            'name' => ['ko' => '각인 추가', 'en' => 'Engrave'],
            'price_adjustment' => 5000,
            'is_default' => true,
            'is_active' => true,
            'allow_custom_text' => true,
            'sort_order' => 0,
        ]);

        $loaded = $this->service->getDetail($product->id, includeInactive: true);
        $array = (new ProductResource($loaded))->toArray(Request::create('/'));

        $values = $array['additional_options'][0]['values'];
        $this->assertCount(1, $values);
        $this->assertSame($value->id, $values[0]['id']);
        $this->assertSame(['ko' => '각인 추가', 'en' => 'Engrave'], $values[0]['name']);
        $this->assertSame(5000, $values[0]['price_adjustment']);
        $this->assertTrue($values[0]['is_default']);
        $this->assertTrue($values[0]['is_active']);
        // 직접입력 허용 플래그가 round-trip 노출된다 (관리자 폼 복원용)
        $this->assertTrue($values[0]['allow_custom_text']);
    }
}
