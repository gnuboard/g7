<?php

namespace Modules\Sirsoft\Page\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * 페이지 버전 팩토리
 *
 * 테스트용 페이지 버전 데이터 생성
 */
class PageVersionFactory extends Factory
{
    /**
     * 모델 클래스
     */
    protected $model = PageVersion::class;

    /**
     * 모델 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'version' => 1,
            'title' => [
                'ko' => $this->faker->sentence(3),
                'en' => $this->faker->sentence(3),
            ],
            'content' => [
                'ko' => $this->faker->paragraphs(2, true),
                'en' => $this->faker->paragraphs(2, true),
            ],
            'content_mode' => 'html',
            'seo_meta' => null,
            'changes_summary' => null,
            'created_by' => User::factory(),
        ];
    }
}
