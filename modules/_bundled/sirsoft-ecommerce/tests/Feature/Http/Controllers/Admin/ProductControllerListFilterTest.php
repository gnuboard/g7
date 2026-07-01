<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductController 목록 필터 테스트 (A19②)
 *
 * no_category / no_brand 같은 boolean 필터가 문자열('true' 등)로 전달되어도
 * 422 없이 정상 동작하는지(prepareForValidation 정규화), 그리고 실제 필터가
 * whereDoesntHave / whereNull 로 적용되는지 검증합니다.
 */
class ProductControllerListFilterTest extends ModuleTestCase
{
    /**
     * A19②: no_category=true(문자열) → 200 + 카테고리 미부여 상품만 표시.
     *
     * 회귀: 문자열 'true' 가 boolean rule 에서 거부되어 422 가 나던 결함 가드.
     * (수정 전 422 → fail)
     */
    public function test_no_category_string_true_returns_only_uncategorized(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $category = Category::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'slug' => 'clothing-filter',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        $categorized = Product::factory()->create();
        $categorized->categories()->attach($category->id);

        $uncategorized = Product::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?no_category=true');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($uncategorized->id, $ids);
        $this->assertNotContains($categorized->id, $ids);
    }

    /**
     * A19②: no_category 의 다양한 boolean 표현 + 누락 cross product 가 422 없이 처리되는지.
     */
    public function test_no_category_boolean_variants_pass_validation(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create();

        foreach (['true', '1', 'false', '0'] as $value) {
            $response = $this->actingAs($user)
                ->getJson('/api/modules/sirsoft-ecommerce/admin/products?no_category='.$value);
            $response->assertOk();
        }

        // 누락(파라미터 없음)도 정상
        $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products')
            ->assertOk();
    }

    /**
     * A19②: no_brand=true(문자열) → 200 + 브랜드 미부여 상품만 표시.
     */
    public function test_no_brand_string_true_returns_only_brandless(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $brand = Brand::create([
            'name' => ['ko' => '브랜드A', 'en' => 'Brand A'],
            'slug' => 'brand-a-filter',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $branded = Product::factory()->create(['brand_id' => $brand->id]);
        $brandless = Product::factory()->create(['brand_id' => null]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?no_brand=true');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($brandless->id, $ids);
        $this->assertNotContains($branded->id, $ids);
    }

    /**
     * A19②: no_category=banana(boolean 해석 불가) → 422 + 키 원문 아닌 번역 한국어 메시지.
     */
    public function test_no_category_invalid_value_returns_translated_422(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?no_category=banana');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('no_category');

        $message = $response->json('errors.no_category.0')
            ?? $response->json('message');

        // 키 원문(sirsoft-ecommerce::validation...)이 아닌 번역된 메시지여야 함
        $this->assertStringNotContainsString('sirsoft-ecommerce::validation', (string) $message);
        $this->assertStringNotContainsString('::', (string) $message);
    }
}
