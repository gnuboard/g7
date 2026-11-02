<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicProductResource;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 옵션별 과세 종류 — 저장·검증·조회·복사·목록 필터 (#612)
 *
 * 상품 기본값(과세/면세/영세) 위에 옵션마다 과세 종류를 덮어쓸 수 있다. 옵션 값 null 은 「상품 따름」이다.
 */
class ProductOptionTaxStatusTest extends ModuleTestCase
{
    private $user;

    private Category $category;

    /**
     * 상품 시퀀스·관리자·카테고리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $defaultConfig = SequenceType::PRODUCT->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::PRODUCT->value],
            [
                'algorithm' => $defaultConfig['algorithm']->value,
                'prefix' => $defaultConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $defaultConfig['max_value'],
                'cycle' => false,
                'pad_length' => $defaultConfig['pad_length'],
                'max_history_count' => $defaultConfig['max_history_count'],
            ]
        );

        $this->user = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
        ]);

        $this->category = new Category([
            'name' => ['ko' => '영세 카테고리', 'en' => 'Zero-rated Category'],
            'slug' => 'zero-rated-612',
            'is_active' => true,
            'depth' => 0,
        ]);
        $this->category->path = 'temp';
        $this->category->save();
        $this->category->generatePath();
        $this->category->save();
    }

    /**
     * 옵션 3개(따름·면세·영세)를 가진 등록 요청 데이터를 만듭니다.
     *
     * @param  string  $productTaxStatus  상품 기본 과세 종류
     * @param  array<int, string|null>  $optionStatuses  옵션별 과세 종류 (null = 상품 따름)
     * @return array<string, mixed> 등록 요청 본문
     */
    private function productPayload(string $productTaxStatus, array $optionStatuses): array
    {
        $options = [];
        foreach ($optionStatuses as $i => $status) {
            $options[] = [
                'option_code' => 'OPT-'.($i + 1),
                'option_name' => ['ko' => '옵션 '.($i + 1), 'en' => 'Option '.($i + 1)],
                'option_values' => [
                    ['key' => ['ko' => '구성', 'en' => 'Set'], 'value' => ['ko' => '옵션 '.($i + 1), 'en' => 'Option '.($i + 1)]],
                ],
                'list_price' => 10000,
                'selling_price' => 10000,
                'stock_quantity' => 10,
                'tax_status' => $status,
            ];
        }

        return [
            'name' => ['ko' => '영세율 검증 상품', 'en' => 'Zero-rated product'],
            'product_code' => 'ZR612-'.uniqid(),
            'category_ids' => [$this->category->id],
            'list_price' => 10000,
            'selling_price' => 10000,
            'stock_quantity' => 30,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => $productTaxStatus,
            'options' => $options,
        ];
    }

    /**
     * 옵션별 과세 종류가 저장되고, 빈 값은 「상품 따름」(null)으로 남는지 확인합니다.
     *
     * @effects option_tax_status_persisted_with_null_as_inherit
     *
     * @return void 반환값 없음
     */
    public function test_store_persists_option_tax_status_and_null_inherit(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', [null, 'tax_free', 'zero_rated']))
            ->assertStatus(201);

        $product = Product::latest('id')->first();
        $statuses = $product->options()->orderBy('sort_order')->get()->map(fn (ProductOption $o) => $o->tax_status?->value)->all();

        $this->assertSame([null, 'tax_free', 'zero_rated'], $statuses);
    }

    /**
     * 상품 기본값으로 영세를 고를 수 있는지 확인합니다.
     *
     * @effects product_tax_status_accepts_zero_rated
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_zero_rated_product_default(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('zero_rated', [null]))
            ->assertStatus(201);

        $this->assertSame(ProductTaxStatus::ZERO_RATED, Product::latest('id')->first()->tax_status);
    }

    /**
     * 정의되지 않은 옵션 과세 종류는 그 옵션 칸의 422 로 거절되는지 확인합니다.
     *
     * @effects option_tax_status_rejects_unknown_value
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_unknown_option_tax_status(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', [null, 'zero']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.1.tax_status']);
    }

    /**
     * 수정 화면 응답이 옵션별 과세 종류를 싣는지 확인합니다 — 새로고침 뒤 선택값 복원 재료.
     *
     * @effects option_tax_status_exposed_on_product_detail
     *
     * @return void 반환값 없음
     */
    public function test_admin_detail_exposes_option_tax_status(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', [null, 'zero_rated']))
            ->assertStatus(201);
        $product = Product::latest('id')->first();

        $response = $this->actingAs($this->user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}")
            ->assertOk();

        $this->assertSame([null, 'zero_rated'], array_column($response->json('data.options'), 'tax_status'));
    }

    /**
     * 판매 정보를 복사하지 않으면 옵션 과세 지정도 지워지고, 복사하면 그대로 내려오는지 확인합니다.
     *
     * @effects option_tax_status_follows_copy_sales_info
     *
     * @return void 반환값 없음
     */
    public function test_copy_keeps_or_resets_option_tax_status_with_sales_info(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', ['zero_rated']))
            ->assertStatus(201);
        $product = Product::latest('id')->first();

        $kept = $this->actingAs($this->user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy")
            ->assertOk();
        $this->assertSame('zero_rated', $kept->json('data.options.0.tax_status'));

        $reset = $this->actingAs($this->user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_sales_info=0")
            ->assertOk();
        $this->assertNull($reset->json('data.options.0.tax_status'));
    }

    /**
     * 목록 과세 필터가 옵션별 지정까지 보는지 확인합니다 — 옵션만 영세인 상품도 「영세」로 걸린다.
     *
     * @effects tax_filter_matches_option_level_status
     *
     * @return void 반환값 없음
     */
    public function test_list_filter_matches_option_level_zero_rated(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', [null, 'zero_rated']))
            ->assertStatus(201);
        $optionOnly = Product::latest('id')->first();

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('taxable', [null]))
            ->assertStatus(201);
        $plainTaxable = Product::latest('id')->first();

        $ids = array_column(
            $this->actingAs($this->user)
                ->getJson('/api/modules/sirsoft-ecommerce/admin/products?tax_status=zero_rated&per_page=50')
                ->assertOk()
                ->json('data.data') ?? [],
            'id'
        );

        $this->assertContains($optionOnly->id, $ids);
        $this->assertNotContains($plainTaxable->id, $ids);
    }

    /**
     * 사용자 상품 상세 응답이 상품·옵션 과세 종류를 싣는지 확인합니다 — 면세·영세 배지 재료.
     *
     * @effects public_product_exposes_tax_status
     *
     * @return void 반환값 없음
     */
    public function test_public_product_exposes_tax_status(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->productPayload('zero_rated', [null, 'taxable']))
            ->assertStatus(201);
        $product = Product::latest('id')->first();

        $resource = json_decode(
            (new PublicProductResource($product->load('activeOptions')))->toResponse(request())->getContent(),
            true,
        )['data'];

        $this->assertSame('zero_rated', $resource['tax_status']);
        $this->assertSame(ProductTaxStatus::ZERO_RATED->label(), $resource['tax_status_label']);
        $this->assertSame([null, 'taxable'], array_column($resource['options'], 'tax_status'));
    }
}
