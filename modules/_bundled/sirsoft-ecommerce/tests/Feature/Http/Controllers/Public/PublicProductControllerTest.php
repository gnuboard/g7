<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductLabelAssignment;
use Modules\Sirsoft\Ecommerce\Models\ProductNotice;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 상품 API Feature 테스트
 *
 * 비로그인 사용자가 접근하는 상품 목록/상세 API 테스트
 */
class PublicProductControllerTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PermissionMiddleware의 guest role 정적 캐시 초기화
        // (TestingSeeder에서 guest 역할을 생성하지만, 정적 캐시가 null로 초기화되지 않을 수 있음)
        $reflection = new \ReflectionClass(\App\Http\Middleware\PermissionMiddleware::class);
        $prop = $reflection->getProperty('guestRoleCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 공개 상품 목록 조회 테스트 - display_status=visible 상품만 반환 (sales_status 무관)
     */
    #[Test]
    public function test_index_returns_only_visible_products(): void
    {
        // Given: visible 상품(다양한 sales_status)과 hidden 상품 생성
        $visibleProduct = Product::factory()->onSale()->create();
        $hiddenProduct = Product::factory()->hidden()->create();
        $soldOutProduct = Product::factory()->soldOut()->create(); // visible + sold_out
        $suspendedProduct = Product::factory()->suspended()->create(); // visible + suspended

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data',
                'pagination' => ['current_page', 'total', 'per_page', 'last_page'],
            ],
        ]);

        $data = $response->json('data.data');
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($visibleProduct->id, $ids);
        $this->assertNotContains($hiddenProduct->id, $ids);
        // 전시중(visible)이면 판매상태와 관계없이 표시
        $this->assertContains($soldOutProduct->id, $ids);
        $this->assertContains($suspendedProduct->id, $ids);
    }

    /**
     * 카테고리 필터 테스트
     */
    #[Test]
    public function test_index_filters_by_category(): void
    {
        // Given: 카테고리와 상품 생성
        $category = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        $productInCategory = Product::factory()->onSale()->create();
        $productInCategory->categories()->attach($category->id);

        $productNotInCategory = Product::factory()->onSale()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?category_id=' . $category->id);

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($productInCategory->id, $ids);
        $this->assertNotContains($productNotInCategory->id, $ids);
    }

    /**
     * 최신순 기본 정렬 테스트
     */
    #[Test]
    public function test_index_default_sort_is_latest(): void
    {
        // Given
        $olderProduct = Product::factory()->onSale()->create(['created_at' => now()->subDay()]);
        $newerProduct = Product::factory()->onSale()->create(['created_at' => now()]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertGreaterThanOrEqual(2, count($data));
        // 최신순이므로 newer가 먼저
        $ids = collect($data)->pluck('id')->toArray();
        $newerIdx = array_search($newerProduct->id, $ids);
        $olderIdx = array_search($olderProduct->id, $ids);
        $this->assertLessThan($olderIdx, $newerIdx);
    }

    /**
     * 가격 오름차순 정렬 테스트
     */
    #[Test]
    public function test_index_sort_by_price_asc(): void
    {
        // Given
        $expensiveProduct = Product::factory()->onSale()->create(['selling_price' => 100000]);
        $cheapProduct = Product::factory()->onSale()->create(['selling_price' => 10000]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?sort=price_asc');

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $cheapIdx = array_search($cheapProduct->id, $ids);
        $expensiveIdx = array_search($expensiveProduct->id, $ids);
        $this->assertLessThan($expensiveIdx, $cheapIdx);
    }

    /**
     * 키워드 검색 테스트
     */
    #[Test]
    public function test_index_search_by_keyword(): void
    {
        // Given
        $matchProduct = Product::factory()->onSale()->create([
            'name' => ['ko' => '삼성 갤럭시 노트', 'en' => 'Samsung Galaxy Note'],
        ]);
        $noMatchProduct = Product::factory()->onSale()->create([
            'name' => ['ko' => '일반 상품', 'en' => 'Normal Product'],
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?search=갤럭시');

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($matchProduct->id, $ids);
        $this->assertNotContains($noMatchProduct->id, $ids);
    }

    /**
     * 페이지네이션 테스트
     */
    #[Test]
    public function test_index_pagination(): void
    {
        // Given: 5개 상품 생성
        Product::factory()->onSale()->count(5)->create();

        // When: per_page=2로 요청
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?per_page=2');

        // Then
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(5, $response->json('data.pagination.total'));
        $this->assertEquals(3, $response->json('data.pagination.last_page'));
    }

    // ========================================
    // popular() 테스트
    // ========================================

    /**
     * 인기 상품 조회 테스트
     */
    #[Test]
    public function test_popular_returns_products(): void
    {
        // Given
        Product::factory()->onSale()->count(3)->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/popular');

        // Then
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data']);
    }

    /**
     * 인기 상품 limit 파라미터 테스트
     */
    #[Test]
    public function test_popular_respects_limit(): void
    {
        // Given
        Product::factory()->onSale()->count(5)->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/popular?limit=2');

        // Then
        $response->assertStatus(200);
        $this->assertLessThanOrEqual(2, count($response->json('data')));
    }

    // ========================================
    // new() 테스트
    // ========================================

    /**
     * 신상품 조회 테스트
     */
    #[Test]
    public function test_new_returns_latest_products(): void
    {
        // Given
        $older = Product::factory()->onSale()->create(['created_at' => now()->subDays(5)]);
        $newer = Product::factory()->onSale()->create(['created_at' => now()]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/new');

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        // 최신이 먼저
        $newerIdx = array_search($newer->id, $ids);
        $olderIdx = array_search($older->id, $ids);
        $this->assertLessThan($olderIdx, $newerIdx);
    }

    // ========================================
    // recent() 테스트
    // ========================================

    /**
     * 최근 본 상품 조회 테스트
     */
    #[Test]
    public function test_recent_returns_products_by_ids(): void
    {
        // Given
        $product1 = Product::factory()->onSale()->create();
        $product2 = Product::factory()->onSale()->create();
        $product3 = Product::factory()->onSale()->create();

        // When: product2, product1 순서로 요청
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/recent?ids=' . $product2->id . ',' . $product1->id);

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($product1->id, $ids);
        $this->assertContains($product2->id, $ids);
        $this->assertNotContains($product3->id, $ids);
    }

    /**
     * 빈 ids 파라미터 시 빈 배열 반환 테스트
     */
    #[Test]
    public function test_recent_returns_empty_when_no_ids(): void
    {
        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/recent');

        // Then
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /**
     * 숨겨진 상품은 recent에서도 제외 테스트
     */
    #[Test]
    public function test_recent_excludes_hidden_products(): void
    {
        // Given
        $visible = Product::factory()->onSale()->create();
        $hidden = Product::factory()->hidden()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/recent?ids=' . $visible->id . ',' . $hidden->id);

        // Then
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    // ========================================
    // show() 테스트
    // ========================================

    /**
     * 상품 상세 조회 테스트
     */
    #[Test]
    public function test_show_returns_visible_product(): void
    {
        // Given
        $product = Product::factory()->onSale()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $product->id);
    }

    /**
     * 상품 상세 조회 시 PublicProductResource 필드 구조 검증 테스트
     */
    #[Test]
    public function test_show_returns_public_product_resource_fields(): void
    {
        // Given
        $product = Product::factory()->onSale()->create([
            'list_price' => 50000,
            'selling_price' => 40000,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'name_localized',
                'product_code',
                'sku',
                'list_price',
                'list_price_formatted',
                'selling_price',
                'selling_price_formatted',
                'discount_rate',
                'multi_currency_list_price',
                'multi_currency_selling_price',
                'stock_quantity',
                'min_purchase_qty',
                'max_purchase_qty',
                'sales_status',
                'sales_status_label',
                'shipping_policy_id',
                'free_shipping',
                'shipping_fee_formatted',
                'short_description',
                'description',
                'has_options',
                'option_groups',
                'is_wishlisted',
                'thumbnail_url',
            ],
        ]);

        // 가격 포맷 검증
        $data = $response->json('data');
        $this->assertStringContainsString('50,000', $data['list_price_formatted']);
        $this->assertStringContainsString('40,000', $data['selling_price_formatted']);
        $this->assertEquals(20, $data['discount_rate']); // (50000-40000)/50000*100 = 20%
        $this->assertFalse($data['is_wishlisted']);
    }

    /**
     * 상품 상세 조회 시 배송정책 정보 반환 테스트
     */
    #[Test]
    public function test_show_returns_shipping_policy(): void
    {
        // Given: 배송정책이 있는 상품
        $shippingPolicy = ShippingPolicy::create([
            'name' => ['ko' => '기본 배송', 'en' => 'Standard Shipping'],
            'shipping_method' => 'parcel',
            'charge_policy' => 'conditional_free',
            'base_fee' => 3000,
            'free_threshold' => 50000,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create([
            'shipping_policy_id' => $shippingPolicy->id,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $response->assertJsonPath('data.shipping_policy.charge_policy', 'conditional_free');
        $this->assertEquals(3000, (int) $response->json('data.shipping_policy.base_fee'));
        $this->assertNotNull($response->json('data.shipping_policy.fee_summary'));
    }

    /**
     * 상품 상세 조회 시 상품정보제공고시 반환 테스트
     */
    #[Test]
    public function test_show_returns_product_notice(): void
    {
        // Given: 상품정보제공고시가 있는 상품 (다국어 name/content 형식)
        $product = Product::factory()->onSale()->create();
        ProductNotice::create([
            'product_id' => $product->id,
            'values' => [
                ['name' => ['ko' => '소재', 'en' => 'Material'], 'content' => ['ko' => '면 100%', 'en' => 'Cotton 100%']],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'content' => ['ko' => 'S, M, L, XL', 'en' => 'S, M, L, XL']],
            ],
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id, [
            'Accept-Language' => 'ko',
        ]);

        // Then
        $response->assertStatus(200);
        $notice = $response->json('data.notice');
        $this->assertNotNull($notice);
        $this->assertCount(2, $notice['values']);
        $this->assertEquals('소재', $notice['values'][0]['label']);
        $this->assertEquals('면 100%', $notice['values'][0]['value']);
        $this->assertEquals('사이즈', $notice['values'][1]['label']);
    }

    /**
     * 상품 상세 조회 시 공통정보 반환 테스트
     */
    #[Test]
    public function test_show_returns_common_info(): void
    {
        // Given: 공통정보가 있는 상품
        $commonInfo = ProductCommonInfo::create([
            'name' => ['ko' => '교환/반품 안내', 'en' => 'Return Policy'],
            'content' => ['ko' => '<p>교환/반품은 7일 이내 가능합니다.</p>', 'en' => '<p>Returns within 7 days.</p>'],
            'content_mode' => 'html',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create([
            'common_info_id' => $commonInfo->id,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $data = $response->json('data.common_info');
        $this->assertNotNull($data);
        $this->assertNotEmpty($data['name']);
        $this->assertNotEmpty($data['content']);
        $this->assertEquals('html', $data['content_mode']);
    }

    /**
     * 상품 상세 조회 시 배송/고시/공통정보 없으면 null 반환 테스트
     */
    #[Test]
    public function test_show_returns_null_for_missing_relations(): void
    {
        // Given: 관계 없는 상품
        $product = Product::factory()->onSale()->create([
            'shipping_policy_id' => null,
            'common_info_id' => null,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $this->assertNull($response->json('data.shipping_policy'));
        $this->assertNull($response->json('data.notice'));
        $this->assertNull($response->json('data.common_info'));
    }

    /**
     * 숨겨진 상품 상세 조회 시 404 테스트
     */
    #[Test]
    public function test_show_returns_404_for_hidden_product(): void
    {
        // Given
        $product = Product::factory()->hidden()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(404);
    }

    /**
     * 상품 상세 조회 시 브랜드명 반환 테스트
     */
    #[Test]
    public function test_show_returns_brand_name(): void
    {
        // Given: 브랜드가 있는 상품
        $brand = Brand::create([
            'name' => ['ko' => '나이키', 'en' => 'Nike'],
            'slug' => 'nike',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create([
            'brand_id' => $brand->id,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $expectedName = app()->getLocale() === 'ko' ? '나이키' : 'Nike';
        $this->assertEquals($expectedName, $response->json('data.brand_name'));
    }

    /**
     * 상품 상세 조회 시 브랜드 없으면 brand_name이 null인지 테스트
     */
    #[Test]
    public function test_show_returns_null_brand_name_when_no_brand(): void
    {
        // Given
        $product = Product::factory()->onSale()->create(['brand_id' => null]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $this->assertNull($response->json('data.brand_name'));
    }

    /**
     * 상품 상세 조회 시 수량 제한 필드 반환 테스트
     */
    #[Test]
    public function test_show_returns_purchase_quantity_limits(): void
    {
        // Given
        $product = Product::factory()->onSale()->create([
            'min_purchase_qty' => 2,
            'max_purchase_qty' => 10,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.min_purchase_qty'));
        $this->assertEquals(10, $response->json('data.max_purchase_qty'));
    }

    /**
     * 상품 상세 조회 시 라벨 반환 테스트
     */
    #[Test]
    public function test_show_returns_labels(): void
    {
        // Given
        $label = ProductLabel::create([
            'name' => ['ko' => 'BEST', 'en' => 'BEST'],
            'color' => '#FF0000',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create();
        ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $labels = $response->json('data.labels');
        $this->assertNotEmpty($labels);
        $this->assertEquals('BEST', $labels[0]['name']);
        $this->assertEquals('#FF0000', $labels[0]['color']);
    }

    /**
     * 상품 상세 조회 시 추가옵션 반환 테스트
     */
    #[Test]
    public function test_show_returns_additional_options(): void
    {
        // Given
        $product = Product::factory()->onSale()->create();
        ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving Text'],
            'is_required' => true,
            'sort_order' => 1,
        ]);
        ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '선물 메시지', 'en' => 'Gift Message'],
            'is_required' => false,
            'sort_order' => 2,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $options = $response->json('data.additional_options');
        $this->assertCount(2, $options);
        $expectedFirst = app()->getLocale() === 'ko' ? '각인 문구' : 'Engraving Text';
        $this->assertEquals($expectedFirst, $options[0]['name']);
        $this->assertTrue($options[0]['is_required']);
        $this->assertFalse($options[1]['is_required']);
    }

    /**
     * 상품 상세 조회 시 notice values가 배열 형태로 변환되는지 테스트 (객체→배열)
     */
    #[Test]
    public function test_show_returns_notice_values_as_array_when_stored_as_object(): void
    {
        // Given: values가 key-value 객체 형태로 저장된 경우
        $product = Product::factory()->onSale()->create();
        ProductNotice::create([
            'product_id' => $product->id,
            'values' => ['원산지' => '대한민국', '제조자' => '(주)삼성'],
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/' . $product->id);

        // Then
        $response->assertStatus(200);
        $values = $response->json('data.notice.values');
        $this->assertIsArray($values);
        $this->assertCount(2, $values);
        // 배열의 각 항목이 label/value 구조를 가지는지 확인
        $this->assertEquals('원산지', $values[0]['label']);
        $this->assertEquals('대한민국', $values[0]['value']);
        $this->assertEquals('제조자', $values[1]['label']);
        $this->assertEquals('(주)삼성', $values[1]['value']);
    }

    /**
     * 인증 없이 접근 가능한지 테스트
     */
    #[Test]
    public function test_public_api_accessible_without_authentication(): void
    {
        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
    }

    // ========================================
    // 라벨 관련 테스트
    // ========================================

    /**
     * 상품 목록에 활성 라벨이 포함되는지 테스트
     */
    #[Test]
    public function test_index_returns_active_labels(): void
    {
        // Given: 활성 라벨이 할당된 상품
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'NEW'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create();
        ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);
        $this->assertNotEmpty($data['labels']);
        // locale에 따라 'ko' 또는 'en' 값이 반환됨
        $expectedName = app()->getLocale() === 'ko' ? '신상품' : 'NEW';
        $this->assertEquals($expectedName, $data['labels'][0]['name']);
        $this->assertEquals('#FF5733', $data['labels'][0]['color']);
    }

    /**
     * 비활성 라벨은 제외되는지 테스트
     */
    #[Test]
    public function test_index_excludes_inactive_labels(): void
    {
        // Given: 비활성 라벨
        $inactiveLabel = ProductLabel::create([
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'color' => '#000000',
            'is_active' => false,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create();
        ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $inactiveLabel->id,
            'start_date' => null,
            'end_date' => null,
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);
        $this->assertEmpty($data['labels']);
    }

    /**
     * 기간 만료된 라벨 할당은 제외되는지 테스트
     */
    #[Test]
    public function test_index_excludes_expired_label_assignments(): void
    {
        // Given: 기간이 만료된 라벨 할당
        $label = ProductLabel::create([
            'name' => ['ko' => '만료', 'en' => 'Expired'],
            'color' => '#999999',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::factory()->onSale()->create();
        ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDay(),
        ]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);
        $this->assertEmpty($data['labels']);
    }

    /**
     * 판매 상태 정보가 목록에 포함되는지 테스트
     */
    #[Test]
    public function test_index_returns_sales_status_fields(): void
    {
        // Given
        $product = Product::factory()->onSale()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);
        $this->assertEquals('on_sale', $data['sales_status']);
        $this->assertNotEmpty($data['sales_status_label']);
    }

    /**
     * 상품 목록에 rating_avg, review_count 필드가 포함되는지 테스트
     */
    #[Test]
    public function test_index_returns_rating_avg_and_review_count_fields(): void
    {
        // Given: 상품 + visible 리뷰 2개(별점 4, 5) + hidden 리뷰 1개(별점 1)
        $product = Product::factory()->onSale()->create();
        ProductReview::factory()->for($product)->create(['rating' => 4, 'status' => ReviewStatus::VISIBLE->value]);
        ProductReview::factory()->for($product)->create(['rating' => 5, 'status' => ReviewStatus::VISIBLE->value]);
        ProductReview::factory()->for($product)->create(['rating' => 1, 'status' => ReviewStatus::HIDDEN->value]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);

        // visible 리뷰 2개만 집계 (hidden 제외)
        $this->assertEquals(2, $data['review_count']);
        // 평균: (4+5)/2 = 4.5
        $this->assertEquals(4.5, $data['rating_avg']);
    }

    /**
     * 리뷰 없는 상품의 rating_avg=0.0, review_count=0 반환 테스트
     */
    #[Test]
    public function test_index_returns_zero_rating_when_no_reviews(): void
    {
        // Given: 리뷰 없는 상품
        $product = Product::factory()->onSale()->create();

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products');

        // Then
        $response->assertStatus(200);
        $data = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($data);
        $this->assertEquals(0, $data['review_count']);
        $this->assertEquals(0.0, $data['rating_avg']);
    }
}