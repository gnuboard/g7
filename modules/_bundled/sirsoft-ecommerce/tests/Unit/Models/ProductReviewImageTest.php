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
 * ProductReviewImage 모델 단위 테스트
 *
 * 해시 자동 생성, 관계, 캐스트, accessor를 검증합니다.
 */
class ProductReviewImageTest extends ModuleTestCase
{
    private ProductReview $review;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createUser();
        $product = Product::factory()->onSale()->create();
        $orderOption = OrderOption::factory()->create(['product_id' => $product->id]);

        $this->review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'order_option_id' => $orderOption->id,
            'user_id' => $user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);
    }

    // ========================================
    // 테이블 및 기본 속성
    // ========================================

    #[Test]
    public function test_uses_correct_table(): void
    {
        $image = new ProductReviewImage;
        $this->assertEquals('ecommerce_product_review_images', $image->getTable());
    }

    #[Test]
    public function test_uses_soft_deletes(): void
    {
        $this->assertContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(ProductReviewImage::class)
        );
    }

    // ========================================
    // 해시 자동 생성
    // ========================================

    #[Test]
    public function test_hash_is_auto_generated_on_create(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        $this->assertNotNull($image->hash);
        $this->assertEquals(12, strlen($image->hash));
    }

    #[Test]
    public function test_hash_is_unique_across_records(): void
    {
        $image1 = ProductReviewImage::factory()->create(['review_id' => $this->review->id]);
        $image2 = ProductReviewImage::factory()->create(['review_id' => $this->review->id]);

        $this->assertNotEquals($image1->hash, $image2->hash);
    }

    #[Test]
    public function test_generate_hash_returns_12_char_string(): void
    {
        $hash = ProductReviewImage::generateHash();

        $this->assertIsString($hash);
        $this->assertEquals(12, strlen($hash));
    }

    // ========================================
    // 캐스트 테스트
    // ========================================

    #[Test]
    public function test_is_thumbnail_is_cast_to_boolean(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'is_thumbnail' => true,
        ]);

        $this->assertIsBool($image->is_thumbnail);
        $this->assertTrue($image->is_thumbnail);
    }

    #[Test]
    public function test_sort_order_is_cast_to_integer(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'sort_order' => 3,
        ]);

        $this->assertIsInt($image->sort_order);
        $this->assertEquals(3, $image->sort_order);
    }

    #[Test]
    public function test_width_and_height_are_cast_to_integer(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'width' => 800,
            'height' => 600,
        ]);

        $this->assertIsInt($image->width);
        $this->assertIsInt($image->height);
    }

    #[Test]
    public function test_file_size_is_cast_to_integer(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'file_size' => 102400,
        ]);

        $this->assertIsInt($image->file_size);
    }

    // ========================================
    // 관계 테스트
    // ========================================

    #[Test]
    public function test_belongs_to_review(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        $this->assertInstanceOf(ProductReview::class, $image->review);
        $this->assertEquals($this->review->id, $image->review->id);
    }

    #[Test]
    public function test_belongs_to_creator(): void
    {
        $user = $this->createUser();
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'created_by' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $image->creator);
        $this->assertEquals($user->id, $image->creator->id);
    }

    // ========================================
    // Accessor 테스트
    // ========================================

    #[Test]
    public function test_download_url_accessor_returns_correct_path(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        $expected = '/api/modules/sirsoft-ecommerce/review-image/' . $image->hash;
        $this->assertEquals($expected, $image->download_url);
    }
}
