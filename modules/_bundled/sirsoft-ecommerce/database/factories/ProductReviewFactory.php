<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;

/**
 * 상품 리뷰 Factory
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'order_option_id' => OrderOption::factory(),
            'user_id' => User::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'content' => $this->faker->paragraph(),
            'content_mode' => 'text',
            'option_snapshot' => null,
            'status' => ReviewStatus::VISIBLE->value,
            'reply_content' => null,
            'reply_content_mode' => 'text',
            'reply_admin_id' => null,
            'replied_at' => null,
        ];
    }
}
