<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Menu>
 */
class MenuFactory extends Factory
{
    /**
     * 다국어 필드 생성
     */
    protected function generateTranslatableField(callable $generator): array
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $data = [];

        foreach ($locales as $locale) {
            $data[$locale] = $generator();
        }

        return $data;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->generateTranslatableField(fn () => $this->faker->words(2, true)),
            'slug' => $this->faker->unique()->slug(),
            'url' => '/admin/'.$this->faker->slug(),
            'icon' => 'fas fa-'.$this->faker->randomElement(['home', 'user', 'cog', 'chart', 'file']),
            'parent_id' => null,
            'order' => $this->faker->numberBetween(1, 100),
            'is_active' => true,
            'module_id' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * 비활성화된 메뉴 상태
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * 모듈 메뉴 상태
     */
    public function moduleMenu(int $moduleId = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'module_id' => $moduleId,
        ]);
    }

    /**
     * 자식 메뉴 상태
     */
    public function childMenu(int $parentId): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentId,
        ]);
    }

    /**
     * 코어 메뉴 상태 (기본값과 동일하지만 명시적)
     */
    public function coreMenu(): static
    {
        return $this->state(fn (array $attributes) => [
            'module_id' => null,
        ]);
    }
}
