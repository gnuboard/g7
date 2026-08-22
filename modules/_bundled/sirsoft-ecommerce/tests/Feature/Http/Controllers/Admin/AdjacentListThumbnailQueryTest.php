<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 인접 목록의 상품 썸네일 재조회 회귀 테스트 (#518 / 공개 #76)
 *
 * `Product::getThumbnailUrl()` 은 `images` 관계가 로드돼 있으면 컬렉션에서 대표를 고르고,
 * 아니면 관계를 **행마다 최대 2회** 다시 조회한다(대표 지정 조회 → 첫 이미지 폴백).
 * 상품목록은 이 관계를 로드하도록 정비했지만 찜 목록·관리자 리뷰 목록은 그대로여서 분기가
 * 작동하지 않고 있었다. 두 목록도 로드하도록 맞춘 뒤, 행 수가 늘어도 조회 수가 늘지 않는지
 * 고정한다.
 */
class AdjacentListThumbnailQueryTest extends ModuleTestCase
{
    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    /** 쿼리 로그 수집을 시작합니다. */
    private function startCapture(): void
    {
        $this->queries = [];

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 상품 이미지 테이블을 읽은 쿼리 수를 셉니다.
     *
     * @return int 이미지 조회 쿼리 수
     */
    private function imageQueryCount(): int
    {
        $table = (new ProductImage)->getTable();

        return count(array_filter(
            $this->queries,
            fn (string $sql) => str_contains($sql, $table),
        ));
    }

    /**
     * 이미지가 있는 상품을 만듭니다.
     *
     * @param  int  $count  생성할 상품 수
     * @return array<int, Product> 생성된 상품 목록
     */
    private function makeProductsWithImages(int $count): array
    {
        $products = [];

        for ($i = 0; $i < $count; $i++) {
            $product = Product::factory()->create();
            $this->makeImage($product, false, 1);
            $this->makeImage($product, true, 2);
            $products[] = $product;
        }

        return $products;
    }

    /**
     * 상품 이미지를 1건 생성합니다.
     *
     * @param  Product  $product  대상 상품
     * @param  bool  $isThumbnail  대표 이미지 여부
     * @param  int  $sortOrder  정렬 순서
     * @return ProductImage 생성된 이미지
     */
    private function makeImage(Product $product, bool $isThumbnail, int $sortOrder): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            // hash 컬럼은 12자 제한 (URL용 고유 해시)
            'hash' => substr(md5($product->id.'-'.$sortOrder.'-'.($isThumbnail ? 't' : 'f')), 0, 12),
            'original_filename' => 'img'.$sortOrder.'.jpg',
            'stored_filename' => 'img'.$sortOrder.'.jpg',
            'disk' => 'public',
            'path' => 'products/img'.$sortOrder.'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_thumbnail' => $isThumbnail,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @scenario surface=wishlist,observation=query_shape
     * @effects thumbnail_query_count_does_not_grow_with_rows, thumbnail_url_falls_back_to_first_image_when_undesignated
     */
    public function test_wishlist_thumbnail_query_count_does_not_grow_with_rows(): void
    {
        $user = User::factory()->create();

        foreach ($this->makeProductsWithImages(5) as $product) {
            ProductWishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);
        }

        $this->startCapture();
        $result = app(ProductWishlistRepositoryInterface::class)->getByUser($user->id, 20);

        // 존재를 먼저 확정한 뒤 쿼리 수를 단언한다.
        $this->assertSame(5, $result->getCollection()->count());

        // 대표 이미지를 실제로 읽어야 분기가 작동했는지 알 수 있다.
        foreach ($result->getCollection() as $row) {
            $this->assertNotNull($row->product->getThumbnailUrl());
        }

        $this->assertSame(
            1,
            $this->imageQueryCount(),
            '이미지 조회는 페이지당 1회여야 한다 — 행마다 재조회하면 행 수에 비례해 늘어난다',
        );
    }

    /**
     * @scenario surface=admin_product_review_list,observation=response_payload
     * @effects product_thumbnail_columns_stay_pruned_without_touching_review_images
     */
    public function test_admin_review_list_thumbnail_query_count_does_not_grow_with_rows(): void
    {
        $user = User::factory()->create();

        foreach ($this->makeProductsWithImages(5) as $product) {
            ProductReview::factory()->create([
                'product_id' => $product->id,
                'user_id' => $user->id,
            ]);
        }

        $this->startCapture();
        $result = app(ProductReviewRepositoryInterface::class)->getListWithFilters([], 20);

        $this->assertSame(5, $result->getCollection()->count());

        foreach ($result->getCollection() as $row) {
            $this->assertNotNull($row->product?->getThumbnailUrl());
        }

        // 리뷰 자체의 첨부 이미지(`images`)도 같은 테이블이 아니므로 이 카운트에 섞이지 않는다.
        $this->assertSame(
            1,
            $this->imageQueryCount(),
            '상품 이미지 조회는 페이지당 1회여야 한다',
        );
    }
}
