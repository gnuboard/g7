<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Models\User;
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

    #[Test]
    public function test_uses_correct_table(): void
    {
        $this->assertEquals('ecommerce_product_reviews', $this->review->getTable());
    }

    #[Test]
    public function test_uses_soft_deletes(): void
    {
        $this->assertContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(ProductReview::class)
        );
    }

    // ========================================
    // 캐스트 테스트
    // ========================================

    #[Test]
    public function test_status_is_cast_to_review_status_enum(): void
    {
        $this->assertInstanceOf(ReviewStatus::class, $this->review->status);
        $this->assertEquals(ReviewStatus::VISIBLE, $this->review->status);
    }

    #[Test]
    public function test_rating_is_cast_to_integer(): void
    {
        $this->assertIsInt($this->review->rating);
        $this->assertEquals(5, $this->review->rating);
    }

    #[Test]
    public function test_option_snapshot_is_cast_to_array(): void
    {
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'option_snapshot' => ['color' => '블랙', 'size' => 'L'],
        ]);

        $this->assertIsArray($review->option_snapshot);
        $this->assertEquals('블랙', $review->option_snapshot['color']);
    }

    #[Test]
    public function test_replied_at_is_cast_to_datetime(): void
    {
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'replied_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $review->replied_at);
    }

    // ========================================
    // 관계 테스트
    // ========================================

    #[Test]
    public function test_belongs_to_product(): void
    {
        $this->assertInstanceOf(Product::class, $this->review->product);
        $this->assertEquals($this->product->id, $this->review->product->id);
    }

    #[Test]
    public function test_belongs_to_order_option(): void
    {
        $this->assertInstanceOf(OrderOption::class, $this->review->orderOption);
        $this->assertEquals($this->orderOption->id, $this->review->orderOption->id);
    }

    #[Test]
    public function test_belongs_to_user(): void
    {
        $this->assertInstanceOf(User::class, $this->review->user);
        $this->assertEquals($this->user->id, $this->review->user->id);
    }

    #[Test]
    public function test_belongs_to_reply_admin(): void
    {
        $admin = User::factory()->create();
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'reply_admin_id' => $admin->id,
            'reply_content' => '감사합니다.',
            'replied_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $review->replyAdmin);
        $this->assertEquals($admin->id, $review->replyAdmin->id);
    }

    #[Test]
    public function test_reply_admin_is_null_when_no_reply(): void
    {
        $this->assertNull($this->review->replyAdmin);
    }

    #[Test]
    public function test_has_many_images(): void
    {
        ProductReviewImage::factory()->count(3)->create([
            'review_id' => $this->review->id,
        ]);

        $this->assertCount(3, $this->review->fresh()->images);
        $this->assertInstanceOf(ProductReviewImage::class, $this->review->fresh()->images->first());
    }

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
