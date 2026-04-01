<?php

namespace Database\Factories;

use App\Models\TemplateLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TemplateLayoutVersion>
 */
class TemplateLayoutVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'layout_id' => TemplateLayout::factory(),
            'version' => fake()->unique()->numberBetween(1, 1000),
            'content' => [
                'version' => '1.0.0',
                'layout_name' => fake()->word(),
                'components' => [],
            ],
            'changes_summary' => json_encode([
                'added' => fake()->numberBetween(1, 20),
                'removed' => fake()->numberBetween(0, 10),
                'is_restored' => false,
                'restored_from' => null,
            ]),
        ];
    }
}
