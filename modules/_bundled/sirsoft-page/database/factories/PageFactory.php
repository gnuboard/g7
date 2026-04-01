<?php

namespace Modules\Sirsoft\Page\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Page\Models\Page;

/**
 * 페이지 팩토리
 *
 * 테스트용 페이지 데이터 생성
 */
class PageFactory extends Factory
{
    /**
     * 모델 클래스
     */
    protected $model = Page::class;

    /**
     * 모델 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->lexify('test-page-??????'),
            'title' => [
                'ko' => $this->faker->sentence(3),
                'en' => $this->faker->sentence(3),
            ],
            'content' => [
                'ko' => $this->faker->paragraphs(2, true),
                'en' => $this->faker->paragraphs(2, true),
            ],
            'content_mode' => 'html',
            'published' => false,
            'published_at' => null,
            'seo_meta' => null,
            'current_version' => 1,
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * 발행 상태로 설정
     *
     * @return static
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * SEO 메타 정보 포함
     *
     * @return static
     */
    public function withSeoMeta(): static
    {
        return $this->state(fn (array $attributes) => [
            'seo_meta' => [
                'title' => $this->faker->sentence(5),
                'description' => $this->faker->sentence(10),
                'keywords' => implode(',', $this->faker->words(5)),
            ],
        ]);
    }
}
