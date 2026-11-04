<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 상세 관련 상품 — 「이 상품은 어떠세요?」 (2026-11-03)
 *
 * 대표 분류(하위 포함) → 그 상위 분류 → … 단계로 채우고, 단계 안 순서와 고른 목록 유지 시간은 환경설정
 * `product_display` 를 따른다. 시험은 순서를 정해 두려고 기본 설정을 「최신 등록 순 + 캐시 안 함」으로 바꿔 둔다.
 *
 * @scenario-manifest tests/scenarios/public-product-related.yaml
 */
class PublicProductRelatedTest extends ModuleTestCase
{
    /**
     * 시험 전 설정값 — 끝나면 되돌린다.
     *
     * @var array<string, mixed>
     */
    private array $previousSettings = [];

    /**
     * 순서를 정해 두는 설정(최신 등록 순, 캐시 안 함)으로 바꿔 둔다
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        $this->previousSettings = (array) ($settings->getSetting('product_display') ?? []);
        $this->setDisplay('latest', 0);

        $this->beforeApplicationDestroyed(function (): void {
            app(EcommerceSettingsService::class)->setSetting('product_display', $this->previousSettings + ['related_order' => 'random', 'related_cache_hours' => 24]);
        });
    }

    /**
     * 관련 상품 설정을 바꾼다
     *
     * @param  string  $order  단계 안 순서
     * @param  int  $hours  목록 유지 시간
     * @return void 반환값 없음
     */
    private function setDisplay(string $order, int $hours): void
    {
        app(EcommerceSettingsService::class)->setSetting('product_display', ['related_order' => $order, 'related_cache_hours' => $hours]);
    }

    /**
     * 분류를 만든다 — path 는 「뿌리/…/자기」 ID 사슬이다
     *
     * @param  string  $slug  분류 주소
     * @param  Category|null  $parent  상위 분류
     * @return Category 분류
     */
    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::create([
            'name' => ['ko' => $slug, 'en' => $slug],
            'slug' => $slug,
            'parent_id' => $parent?->id,
            'is_active' => true,
            'depth' => $parent ? $parent->depth + 1 : 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => $parent ? $parent->path.'/'.$category->id : (string) $category->id]);

        return $category;
    }

    /**
     * 분류에 건 공개 상품을 만든다 — 만든 순서대로 등록 시각이 늦어진다
     *
     * @param  array<int, Category>  $categories  걸 분류 (첫 분류가 대표)
     * @param  array<string, mixed>  $attributes  덮어쓸 상품 값
     * @return Product 상품
     */
    private function product(array $categories, array $attributes = []): Product
    {
        static $minutes = 0;
        $product = Product::factory()->onSale()->create($attributes + ['created_at' => now()->addMinutes(++$minutes)]);
        foreach ($categories as $i => $category) {
            $product->categories()->attach($category->id, ['is_primary' => $i === 0]);
        }

        return $product;
    }

    /**
     * 관련 상품 ID 목록을 받는다
     *
     * @param  Product  $product  기준 상품
     * @param  int|null  $limit  최대 개수
     * @return array<int, int> 응답 순서대로의 상품 ID
     */
    private function relatedIds(Product $product, ?int $limit = null): array
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/'.$product->id.'/related'.($limit ? '?limit='.$limit : ''));
        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data'), 'id'));
    }

    /**
     * 캠핑 > 캠핑 의자 > 국산 캠핑 의자 세 단계와 다른 갈래(의상)를 깐다
     *
     * @return array<string, Category> 분류
     */
    private function tree(): array
    {
        $root = $this->category('drums');
        $mid = $this->category('drum-sticks', $root);
        $leaf = $this->category('drum-sticks-domestic', $mid);
        $other = $this->category('costumes');

        return compact('root', 'mid', 'leaf', 'other');
    }

    /**
     * 대표 분류 → 상위 → 그 위 순서로 채우고 다른 갈래 분류 상품은 넣지 않는다
     *
     * @scenario case=tier_fill
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_fills_by_category_tier(): void
    {
        ['root' => $root, 'mid' => $mid, 'leaf' => $leaf, 'other' => $other] = $this->tree();
        $rootOnly = $this->product([$root]);
        $midOnly = $this->product([$mid]);
        $leafA = $this->product([$leaf]);
        $leafB = $this->product([$leaf]);
        $this->product([$other]);
        $base = $this->product([$leaf]);

        $this->assertSame([$leafB->id, $leafA->id, $midOnly->id, $rootOnly->id], $this->relatedIds($base));
    }

    /**
     * 앞 단계만으로 개수가 차면 상위 단계 상품은 들어오지 않는다
     *
     * @scenario case=limit_cut
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_limit_is_filled_from_nearest_tier_first(): void
    {
        ['root' => $root, 'leaf' => $leaf] = $this->tree();
        $this->product([$root]);
        $leafA = $this->product([$leaf]);
        $leafB = $this->product([$leaf]);
        $base = $this->product([$leaf]);

        $this->assertSame([$leafB->id, $leafA->id], $this->relatedIds($base, 2));
    }

    /**
     * 지금 상품·숨긴 상품은 빼고, 두 단계에 걸린 상품은 한 번만 나온다
     *
     * @scenario case=exclusions
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_excludes_self_hidden_and_duplicates(): void
    {
        ['mid' => $mid, 'leaf' => $leaf] = $this->tree();
        $both = $this->product([$leaf, $mid]);
        $this->product([$leaf], ['display_status' => 'hidden']);
        $base = $this->product([$leaf]);

        $ids = $this->relatedIds($base);

        $this->assertSame([$both->id], $ids);
        $this->assertNotContains($base->id, $ids);
    }

    /**
     * 분류가 없는 상품은 빈 목록이다
     *
     * @scenario case=no_category
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_product_without_category_returns_empty(): void
    {
        $this->tree();
        $base = $this->product([]);

        $this->assertSame([], $this->relatedIds($base));
    }

    /**
     * 순서 설정이 리뷰 많은 순이면 단계 안에서 노출 리뷰가 많은 상품이 먼저다
     *
     * @scenario case=order_reviews
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_reviews_order_setting_sorts_within_tier(): void
    {
        $this->setDisplay('reviews', 0);
        ['mid' => $mid, 'leaf' => $leaf] = $this->tree();
        $midMany = $this->product([$mid]);
        $leafFew = $this->product([$leaf]);
        $leafMany = $this->product([$leaf]);
        $leafNone = $this->product([$leaf]);
        foreach ([[$leafMany, 3], [$leafFew, 1], [$midMany, 5]] as [$product, $count]) {
            for ($i = 0; $i < $count; $i++) {
                ProductReview::factory()->for($product)->create(['rating' => 5, 'status' => ReviewStatus::VISIBLE->value]);
            }
        }
        $base = $this->product([$leaf]);

        // 상위 단계의 리뷰 많은 상품이 대표 분류 상품을 앞지르지 않는다
        $this->assertSame([$leafMany->id, $leafFew->id, $leafNone->id, $midMany->id], $this->relatedIds($base));
    }

    /**
     * 유지 시간 동안은 새 상품이 생겨도 같은 목록이다
     *
     * @scenario case=cache_kept
     *
     * @effects related_products_cache_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_list_is_kept_for_cache_hours(): void
    {
        $this->setDisplay('latest', 24);
        ['leaf' => $leaf] = $this->tree();
        $first = $this->product([$leaf]);
        $base = $this->product([$leaf]);

        $this->assertSame([$first->id], $this->relatedIds($base));
        $this->product([$leaf]);
        $this->assertSame([$first->id], $this->relatedIds($base));
    }

    /**
     * 유지 시간이 0 이면 볼 때마다 새로 고른다
     *
     * @scenario case=cache_off
     *
     * @effects related_products_cache_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_zero_cache_hours_picks_on_every_view(): void
    {
        ['leaf' => $leaf] = $this->tree();
        $first = $this->product([$leaf]);
        $base = $this->product([$leaf]);

        $this->assertSame([$first->id], $this->relatedIds($base));
        $added = $this->product([$leaf]);
        $this->assertSame([$added->id, $first->id], $this->relatedIds($base));
    }

    /**
     * 관리자가 상품 진열 설정을 저장하면 골라 둔 목록을 비워 새 설정으로 다시 고른다
     *
     * @scenario case=settings_save_flush
     *
     * @effects related_products_cache_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_saving_settings_flushes_cached_lists(): void
    {
        $this->setDisplay('latest', 24);
        ['leaf' => $leaf] = $this->tree();
        $first = $this->product([$leaf]);
        $base = $this->product([$leaf]);
        $this->assertSame([$first->id], $this->relatedIds($base));
        $added = $this->product([$leaf]);

        $this->actingAs($this->settingsAdmin())
            ->putJson('/api/modules/sirsoft-ecommerce/admin/settings', ['_tab' => 'product_display', 'product_display' => ['related_order' => 'latest', 'related_cache_hours' => 24]])
            ->assertStatus(200);

        // 공개 상품 API 는 손님 시점으로 다시 본다 — 시험용 관리자 역할에는 사용자 상품 읽기 권한이 없다
        $this->app['auth']->forgetGuards();

        $this->assertSame([$added->id, $first->id], $this->relatedIds($base));
    }

    /**
     * 순서 허용값 밖이나 유지 시간 범위(0~720) 밖은 저장하지 않는다
     *
     * @scenario case=settings_validation
     *
     * @effects related_products_settings_validated
     *
     * @return void 반환값 없음
     */
    public function test_settings_validation(): void
    {
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/settings', ['_tab' => 'product_display', 'product_display' => ['related_order' => 'price_asc', 'related_cache_hours' => 24]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_display.related_order']);

        $this->actingAs($admin)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/settings', ['_tab' => 'product_display', 'product_display' => ['related_order' => 'random', 'related_cache_hours' => 721]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_display.related_cache_hours']);
    }

    /**
     * 기준 상품이 숨김이면 404
     *
     * @scenario case=hidden_base
     *
     * @effects related_products_fill_by_category_tier
     *
     * @return void 반환값 없음
     */
    public function test_hidden_base_product_returns_404(): void
    {
        ['leaf' => $leaf] = $this->tree();
        $this->product([$leaf]);
        $base = $this->product([$leaf], ['display_status' => 'hidden']);

        $this->getJson('/api/modules/sirsoft-ecommerce/products/'.$base->id.'/related')->assertStatus(404);
    }

    /**
     * 설정 조회·수정 권한을 가진 관리자
     *
     * @return User 관리자
     */
    private function settingsAdmin(): User
    {
        return $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }
}
