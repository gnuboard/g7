<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
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
            'identifier' => $this->faker->unique()->slug(1),
            'name' => $this->generateTranslatableField(fn () => $this->faker->words(2, true)),
            'description' => $this->generateTranslatableField(fn () => $this->faker->sentence()),
            'is_active' => true,
        ];
    }

    /**
     * 비활성화된 역할 상태
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * 관리자 역할
     */
    public function admin(): static
    {
        // 기본 번역 정의
        $defaultTranslations = [
            'name' => [
                'ko' => '관리자',
                'en' => 'Administrator',
            ],
            'description' => [
                'ko' => '시스템의 모든 권한을 가진 관리자입니다.',
                'en' => 'Full system administrator with all permissions.',
            ],
        ];

        // config에서 로케일 가져오기
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $nameTranslations = [];
        $descriptionTranslations = [];

        foreach ($locales as $locale) {
            // 정의된 번역이 있으면 사용, 없으면 영어 fallback
            $nameTranslations[$locale] = $defaultTranslations['name'][$locale]
                ?? $defaultTranslations['name']['en'];
            $descriptionTranslations[$locale] = $defaultTranslations['description'][$locale]
                ?? $defaultTranslations['description']['en'];
        }

        return $this->state(fn (array $attributes) => [
            'identifier' => 'admin',
            'name' => $nameTranslations,
            'description' => $descriptionTranslations,
        ]);
    }

    /**
     * 편집자 역할
     */
    public function editor(): static
    {
        // 기본 번역 정의
        $defaultTranslations = [
            'name' => [
                'ko' => '편집자',
                'en' => 'Editor',
            ],
            'description' => [
                'ko' => '콘텐츠를 편집할 수 있는 편집자입니다.',
                'en' => 'Content editor with editing permissions.',
            ],
        ];

        // config에서 로케일 가져오기
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $nameTranslations = [];
        $descriptionTranslations = [];

        foreach ($locales as $locale) {
            // 정의된 번역이 있으면 사용, 없으면 영어 fallback
            $nameTranslations[$locale] = $defaultTranslations['name'][$locale]
                ?? $defaultTranslations['name']['en'];
            $descriptionTranslations[$locale] = $defaultTranslations['description'][$locale]
                ?? $defaultTranslations['description']['en'];
        }

        return $this->state(fn (array $attributes) => [
            'identifier' => 'editor',
            'name' => $nameTranslations,
            'description' => $descriptionTranslations,
        ]);
    }
}
