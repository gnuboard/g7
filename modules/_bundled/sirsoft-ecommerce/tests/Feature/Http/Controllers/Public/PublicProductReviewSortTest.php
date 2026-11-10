<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Http\Middleware\PermissionMiddleware;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 상품 목록 「리뷰 많은 순」 정렬 (sort=reviews)
 *
 * 노출 중인 리뷰 수가 많은 상품부터, 같으면 최신 등록순. 숨긴 리뷰는 세지 않는다.
 *
 * @scenario-manifest tests/scenarios/public-product-sort-reviews.yaml
 */
class PublicProductReviewSortTest extends ModuleTestCase
{
    /**
     * 테스트 준비 — 공개 목록 권한 미들웨어를 건너뛴다(다른 공개 목록 시험과 같음)
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PermissionMiddleware::class);
    }

    /**
     * 상품에 리뷰를 붙인다
     *
     * @param  Product  $product  상품
     * @param  int  $visible  노출 리뷰 수
     * @param  int  $hidden  숨긴 리뷰 수
     * @return void 반환값 없음
     */
    private function reviews(Product $product, int $visible, int $hidden = 0): void
    {
        for ($i = 0; $i < $visible; $i++) {
            ProductReview::factory()->for($product)->create(['rating' => 5, 'status' => ReviewStatus::VISIBLE->value]);
        }
        for ($i = 0; $i < $hidden; $i++) {
            ProductReview::factory()->for($product)->create(['rating' => 1, 'status' => ReviewStatus::HIDDEN->value]);
        }
    }

    /**
     * 노출 리뷰 수 내림차순, 같으면 최신 등록순 — 숨긴 리뷰는 세지 않는다
     *
     * @scenario sort=reviews
     *
     * @effects public_list_sorted_by_visible_review_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_sort_reviews_orders_by_visible_review_count_then_latest(): void
    {
        $noneNew = Product::factory()->onSale()->create(['created_at' => now()]);
        $many = Product::factory()->onSale()->create(['created_at' => now()->subDays(3)]);
        $fewWithHidden = Product::factory()->onSale()->create(['created_at' => now()->subDays(2)]);
        $noneOld = Product::factory()->onSale()->create(['created_at' => now()->subDays(5)]);
        $this->reviews($many, 3);
        $this->reviews($fewWithHidden, 1, 3);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?sort=reviews&per_page=50');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $order = array_values(array_intersect($ids, [$noneNew->id, $many->id, $fewWithHidden->id, $noneOld->id]));
        $this->assertSame([$many->id, $fewWithHidden->id, $noneNew->id, $noneOld->id], $order);

        // 목록 응답의 리뷰 수도 노출 리뷰만 센 값이다
        $row = collect($response->json('data.data'))->firstWhere('id', $fewWithHidden->id);
        $this->assertSame(1, (int) ($row['review_count'] ?? -1));
    }

    /**
     * 알 수 없는 정렬 값은 여전히 422
     *
     * @scenario sort=unknown
     *
     * @effects public_list_sorted_by_visible_review_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_unknown_sort_is_rejected(): void
    {
        $this->getJson('/api/modules/sirsoft-ecommerce/products?sort=review_count')->assertStatus(422);
    }
}
