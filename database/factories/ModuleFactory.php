<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Module>
 */
class ModuleFactory extends Factory
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
            'identifier' => $this->faker->unique()->slug(2),
            'vendor' => $this->faker->word(),
            'name' => $this->generateTranslatableField(fn () => $this->faker->words(2, true)),
            'version' => $this->faker->numerify('#.#.#'),
            'status' => 'active',
            'description' => $this->generateTranslatableField(fn () => $this->faker->sentence()),
            'config' => null,
            'metadata' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
