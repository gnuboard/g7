<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductReview 모델 단위 테스트
 *
 * 관계, 캐스트, 테이블명, fillable 속성을 검증합니다.
 */
class ProductReviewTest extends ModuleTestCase
{
    private ProductReview $review;

    private Product $product;

    private User $user;

    private OrderOption $orderOption;

    /**
     * 후기 작성 회원·판매중 상품·주문옵션과 전시 중 별점 5 후기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->product = Product::factory()->onSale()->create();
        $this->orderOption = OrderOption::factory()->create([
            'product_id' => $this->product->id,
        ]);

        $this->review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
            'rating' => 5,
            'content' => '테스트 리뷰 내용입니다. 상품이 좋습니다.',
        ]);
    }

    // ========================================
    // 테이블 및 기본 속성
    // ========================================

    /**
     * 후기 모델이 ecommerce_product_reviews 테이블을 쓰는지 확인합니다.
     *
     * @scenario aspect=table
     *
     * @effects product_review_uses_reviews_table_with_soft_deletes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_uses_correct_table(): void
    {
        $this->assertEquals('ecommerce_product_reviews', $this->review->getTable());
    }

    /**
     * 후기 모델이 SoftDeletes 트레이트를 쓰는지 확인합니다.
     *
     * @scenario aspect=table
     *
     * @effects product_review_uses_reviews_table_with_soft_deletes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_uses_soft_deletes(): void
    {
        $this->assertContains(
            SoftDeletes::class,
            class_uses_recursive(ProductReview::class)
        );
    }

    // ========================================
    // 캐스트 테스트
    // ========================================

    /**
     * status 가 ReviewStatus 열거형으로 캐스트되는지 확인합니다.
     *
     * @scenario aspect=cast
     *
     * @effects product_review_casts_status_rating_snapshot_and_reply_time
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_status_is_cast_to_review_status_enum(): void
    {
        $this->assertInstanceOf(ReviewStatus::class, $this->review->status);
        $this->assertEquals(ReviewStatus::VISIBLE, $this->review->status);
    }

    /**
     * rating 이 정수로 캐스트되는지 확인합니다.
     *
     * @scenario aspect=cast
     *
     * @effects product_review_casts_status_rating_snapshot_and_reply_time
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_rating_is_cast_to_integer(): void
    {
        $this->assertIsInt($this->review->rating);
        $this->assertEquals(5, $this->review->rating);
    }

    /**
     * option_snapshot 이 배열로 캐스트되는지 확인합니다.
     *
     * @scenario aspect=cast
     *
     * @effects product_review_casts_status_rating_snapshot_and_reply_time
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_option_snapshot_is_cast_to_array(): void
    {
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            // 주문옵션당 활성 후기는 1건뿐이라(DB 유니크) setUp 후기와 다른 주문옵션을 쓴다
            'order_option_id' => OrderOption::factory()->state(['product_id' => $this->product->id]),
            'user_id' => $this->user->id,
            'option_snapshot' => ['color' => '블랙', 'size' => 'L'],
        ]);

        $this->assertIsArray($review->option_snapshot);
        $this->assertEquals('블랙', $review->option_snapshot['color']);
    }

    /**
     * replied_at 이 Carbon 날짜시각으로 캐스트되는지 확인합니다.
     *
     * @scenario aspect=cast
     *
     * @effects product_review_casts_status_rating_snapshot_and_reply_time
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_replied_at_is_cast_to_datetime(): void
    {
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            // 주문옵션당 활성 후기는 1건뿐이라(DB 유니크) setUp 후기와 다른 주문옵션을 쓴다
            'order_option_id' => OrderOption::factory()->state(['product_id' => $this->product->id]),
            'user_id' => $this->user->id,
            'replied_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $review->replied_at);
    }

    // ========================================
    // 관계 테스트
    // ========================================

    /**
     * 후기가 상품에 속하는 관계를 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_belongs_to_product_option_user_and_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_belongs_to_product(): void
    {
        $this->assertInstanceOf(Product::class, $this->review->product);
        $this->assertEquals($this->product->id, $this->review->product->id);
    }

    /**
     * 후기가 주문옵션에 속하는 관계를 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_belongs_to_product_option_user_and_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_belongs_to_order_option(): void
    {
        $this->assertInstanceOf(OrderOption::class, $this->review->orderOption);
        $this->assertEquals($this->orderOption->id, $this->review->orderOption->id);
    }

    /**
     * 후기가 작성 회원에 속하는 관계를 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_belongs_to_product_option_user_and_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_belongs_to_user(): void
    {
        $this->assertInstanceOf(User::class, $this->review->user);
        $this->assertEquals($this->user->id, $this->review->user->id);
    }

    /**
     * 답변이 있는 후기가 답변 관리자에 속하는 관계를 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_belongs_to_product_option_user_and_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_belongs_to_reply_admin(): void
    {
        $admin = User::factory()->create();
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            // 주문옵션당 활성 후기는 1건뿐이라(DB 유니크) setUp 후기와 다른 주문옵션을 쓴다
            'order_option_id' => OrderOption::factory()->state(['product_id' => $this->product->id]),
            'user_id' => $this->user->id,
            'reply_admin_id' => $admin->id,
            'reply_content' => '감사합니다.',
            'replied_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $review->replyAdmin);
        $this->assertEquals($admin->id, $review->replyAdmin->id);
    }

    /**
     * 답변이 없는 후기의 답변 관리자 관계는 null 인지 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_reply_admin_null_without_reply
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_reply_admin_is_null_when_no_reply(): void
    {
        $this->assertNull($this->review->replyAdmin);
    }

    /**
     * 후기가 여러 이미지를 갖는 관계를 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_images_loaded_in_sort_order
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_has_many_images(): void
    {
        ProductReviewImage::factory()->count(3)->create([
            'review_id' => $this->review->id,
        ]);

        $this->assertCount(3, $this->review->fresh()->images);
        $this->assertInstanceOf(ProductReviewImage::class, $this->review->fresh()->images->first());
    }

    /**
     * 이미지 관계가 sort_order 오름차순으로 읽히는지 확인합니다.
     *
     * @scenario aspect=relation
     *
     * @effects product_review_images_loaded_in_sort_order
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_images_are_ordered_by_sort_order(): void
    {
        ProductReviewImage::factory()->create(['review_id' => $this->review->id, 'sort_order' => 3]);
        ProductReviewImage::factory()->create(['review_id' => $this->review->id, 'sort_order' => 1]);
        ProductReviewImage::factory()->create(['review_id' => $this->review->id, 'sort_order' => 2]);

        $images = $this->review->fresh()->images;

        $this->assertEquals(1, $images[0]->sort_order);
        $this->assertEquals(2, $images[1]->sort_order);
        $this->assertEquals(3, $images[2]->sort_order);
    }
}
