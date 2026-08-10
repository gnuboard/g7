<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Resources;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\WishlistResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 목록 응답의 리뷰 통계 정확도 회귀 테스트 (#519)
 *
 * 평점·리뷰 수는 상품 표에 저장된 값이 아니라 조회 시 조인으로 붙는 집계다. 그래서 집계를
 * 붙이지 않은 조회 경로에서는 그 속성이 **아예 없는** 상태로 온다.
 *
 * 종전 소비 판정은 `$this->rating_avg !== null` 이었다. 값 검사는 "집계를 안 했다" 와
 * "집계했더니 0건이다" 를 구분하지 못하므로, 리뷰가 달린 상품도 집계를 붙이지 않은 경로에서는
 * `rating_avg: 0.0` · `review_count: 0` 으로 나갔다 — 틀린 값이 사실처럼 나가고 예외도
 * 경고도 남지 않는다.
 *
 * 두 방향을 함께 고정한다.
 *   1. 최근 본 상품 조회는 집계를 붙여 **실제 값**을 돌려준다.
 *   2. 집계를 붙이지 않은 경로에서는 0 을 지어내지 않고 **필드를 생략**한다.
 *      (집계를 붙였는데 리뷰가 0건이면 별칭은 존재하고 값만 null 이므로 종전대로 0 으로 표기)
 *
 * @scenario case=product_list_review_aggregate
 *
 * @effects recently_viewed_products_carry_real_review_stats
 * @effects list_resource_omits_review_stats_when_not_aggregated
 */
class ProductListReviewAggregateTest extends ModuleTestCase
{
    /**
     * 최근 본 상품 조회가 실제 리뷰 통계를 싣는지 확인합니다.
     *
     * @effects recently_viewed_products_carry_real_review_stats
     */
    public function test_recently_viewed_products_carry_real_review_stats(): void
    {
        $product = Product::factory()->create(['display_status' => 'visible']);
        $this->makeVisibleReview($product, 5);
        $this->makeVisibleReview($product, 4);

        $products = app(ProductService::class)->getProductsByIds([$product->id]);

        $payload = ProductListResource::collection($products)
            ->resolve(request());

        $this->assertCount(1, $payload);
        $this->assertSame(
            2,
            $payload[0]['review_count'] ?? null,
            '최근 본 상품 응답의 리뷰 수가 실제 건수와 다릅니다 — 집계를 붙이지 않으면 0 이 나갑니다.'
        );
        $this->assertSame(
            4.5,
            $payload[0]['rating_avg'] ?? null,
            '최근 본 상품 응답의 평점이 실제 평균과 다릅니다.'
        );
    }

    /**
     * 리뷰가 0건이면 집계가 붙어도 0 으로 표기되는지 확인합니다.
     */
    public function test_product_without_reviews_reports_zero_stats(): void
    {
        $product = Product::factory()->create(['display_status' => 'visible']);

        $products = app(ProductService::class)->getProductsByIds([$product->id]);

        $payload = ProductListResource::collection($products)->resolve(request());

        $this->assertSame(0, $payload[0]['review_count'] ?? null);
        $this->assertSame(0.0, $payload[0]['rating_avg'] ?? null);
    }

    /**
     * 집계를 붙이지 않은 모델에서는 리뷰 통계 필드가 생략되는지 확인합니다.
     *
     * @effects list_resource_omits_review_stats_when_not_aggregated
     */
    public function test_list_resource_omits_review_stats_when_not_aggregated(): void
    {
        $product = Product::factory()->create(['display_status' => 'visible']);
        $this->makeVisibleReview($product, 5);

        // 집계 없이 그대로 읽은 모델 — 별칭 자체가 붙지 않는다.
        $raw = Product::query()->findOrFail($product->id);

        $payload = (new ProductListResource($raw))->resolve(request());

        $this->assertArrayNotHasKey(
            'review_count',
            $payload,
            '집계하지 않은 경로인데 리뷰 수가 실렸습니다 — 0 이 정확한 값처럼 나갑니다.'
        );
        $this->assertArrayNotHasKey(
            'rating_avg',
            $payload,
            '집계하지 않은 경로인데 평점이 실렸습니다.'
        );
    }

    /**
     * 찜 목록 카드도 실제 리뷰 통계를 싣는지 확인합니다.
     *
     * 찜 화면은 상품 목록과 같은 카드 컴포넌트를 쓰므로 별점을 그린다. 집계를 붙이지 않으면
     * 리뷰가 달린 상품도 별 0 개로 보인다.
     *
     * @effects wishlist_cards_carry_real_review_stats
     */
    public function test_wishlist_cards_carry_real_review_stats(): void
    {
        $owner = User::factory()->create();
        $product = Product::factory()->create(['display_status' => 'visible']);
        $this->makeVisibleReview($product, 5);
        $this->makeVisibleReview($product, 4);

        ProductWishlist::create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);

        $wishlists = app(ProductWishlistRepositoryInterface::class)->getByUser($owner->id, 20);

        $payload = WishlistResource::collection($wishlists->getCollection())->resolve(request());

        $this->assertCount(1, $payload);
        $this->assertSame(
            2,
            $payload[0]['product']['review_count'] ?? null,
            '찜 카드의 리뷰 수가 실제 건수와 다릅니다 — 집계를 붙이지 않으면 별 0 개로 보입니다.'
        );
        $this->assertSame(4.5, $payload[0]['product']['rating_avg'] ?? null);
    }

    /**
     * 노출 상태 리뷰를 1건 만듭니다.
     *
     * @param  Product  $product  대상 상품
     * @param  int  $rating  평점
     */
    private function makeVisibleReview(Product $product, int $rating): void
    {
        ProductReview::factory()->create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'rating' => $rating,
            'status' => ReviewStatus::VISIBLE->value,
        ]);
    }
}
