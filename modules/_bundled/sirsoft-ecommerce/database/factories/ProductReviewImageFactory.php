<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;

/**
 * 상품 리뷰 이미지 Factory
 */
class ProductReviewImageFactory extends Factory
{
    protected $model = ProductReviewImage::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $filename = $this->faker->uuid().'.jpg';

        return [
            'review_id' => ProductReview::factory(),
            'original_filename' => 'review_photo.jpg',
            'stored_filename' => $filename,
            'disk' => 'local',
            'path' => "reviews/1/{$filename}",
            'mime_type' => 'image/jpeg',
            'file_size' => $this->faker->numberBetween(10000, 500000),
            'width' => 800,
            'height' => 600,
            'alt_text' => null,
            'collection' => 'review',
            'is_thumbnail' => false,
            'sort_order' => 1,
            'created_by' => null,
        ];
    }
}
