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
        // 모델 cast 가 'array' 로 지정되어 있으므로 JSON 인코딩은 Laravel 이 자동 수행한다.
        // Repository::buildChangesSummary 가 생성하는 스키마와 일치시킨다:
        //   added/removed/modified = 배열(경로 문자열 목록), char_diff = 정수.
        return [
            'layout_id' => TemplateLayout::factory(),
            'version' => fake()->unique()->numberBetween(1, 1000),
            'content' => [
                'version' => '1.0.0',
                'layout_name' => fake()->word(),
                'components' => [],
            ],
            'changes_summary' => [
                'added' => [],
                'removed' => [],
                'modified' => [],
                'char_diff' => fake()->numberBetween(-500, 500),
            ],
        ];
    }
}
