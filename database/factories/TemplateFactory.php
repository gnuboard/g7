<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Template>
 */
class TemplateFactory extends Factory
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
        $vendor = fake()->randomElement(['sirsoft', 'johndoe', 'acmecorp']);
        $type = fake()->randomElement(['admin', 'user']);

        return [
            'identifier' => $vendor.'-'.fake()->slug(2),
            'vendor' => $vendor,
            'name' => $this->generateTranslatableField(fn () => fake()->words(2, true)),
            'version' => fake()->semver(),
            'latest_version' => fake()->semver(),
            'update_available' => fake()->boolean(30),
            'type' => $type,
            'status' => fake()->randomElement(['active', 'inactive']),
            'description' => $this->generateTranslatableField(fn () => fake()->sentence()),
            'user_modified_at' => fake()->boolean(50) ? fake()->dateTime() : null,
            'github_url' => fake()->url(),
            'github_changelog_url' => fake()->url(),
            'metadata' => [
                'author' => fake()->name(),
                'license' => 'MIT',
            ],
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * 한국어만 있는 템플릿 상태
     */
    public function koreanOnly(): static
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $nameTranslations = [];
        $descriptionTranslations = [];

        foreach ($locales as $locale) {
            $nameTranslations[$locale] = $locale === 'ko' ? fake()->words(2, true) : '';
            $descriptionTranslations[$locale] = $locale === 'ko' ? fake()->sentence() : '';
        }

        return $this->state(fn (array $attributes) => [
            'name' => $nameTranslations,
            'description' => $descriptionTranslations,
        ]);
    }

    /**
     * 영어만 있는 템플릿 상태
     */
    public function englishOnly(): static
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $nameTranslations = [];
        $descriptionTranslations = [];

        foreach ($locales as $locale) {
            $nameTranslations[$locale] = $locale === 'en' ? fake()->words(2, true) : '';
            $descriptionTranslations[$locale] = $locale === 'en' ? fake()->sentence() : '';
        }

        return $this->state(fn (array $attributes) => [
            'name' => $nameTranslations,
            'description' => $descriptionTranslations,
        ]);
    }
}
