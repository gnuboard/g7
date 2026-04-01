<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;

/**
 * 상품 1:1 문의 Factory (테스트 전용)
 */
class ProductInquiryFactory extends Factory
{
    protected $model = ProductInquiry::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'product_id'            => null,
            'inquirable_type'       => 'board_post',
            'inquirable_id'         => $this->faker->unique()->numberBetween(1, 99999),
            'user_id'               => null,
            'is_answered'           => false,
            'answered_at'           => null,
            'product_name_snapshot' => [
                'ko' => $this->faker->words(3, true),
                'en' => $this->faker->words(3, true),
            ],
        ];
    }

    /**
     * 답변완료 상태
     *
     * @return static
     */
    public function answered(): static
    {
        return $this->state(fn () => [
            'is_answered' => true,
            'answered_at' => now(),
        ]);
    }
}
