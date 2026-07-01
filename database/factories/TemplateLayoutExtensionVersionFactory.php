<?php

namespace Database\Factories;

use App\Models\LayoutExtension;
use App\Models\TemplateLayoutExtensionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateLayoutExtensionVersion>
 */
class TemplateLayoutExtensionVersionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TemplateLayoutExtensionVersion>
     */
    protected $model = TemplateLayoutExtensionVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'extension_id' => LayoutExtension::factory(),
            'version' => fake()->unique()->numberBetween(1, 1000),
            'content' => [
                'extension_point' => fake()->word(),
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
