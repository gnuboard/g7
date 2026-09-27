<?php

namespace Database\Factories\Analytics;

use App\Models\Analytics\AnalyticsActionName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsActionName>
 */
class AnalyticsActionNameFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsActionName>
     */
    protected $model = AnalyticsActionName::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $name = '/'.$this->faker->unique()->slug();

        return [
            'type' => 'url',
            'name' => $name,
            'name_hash' => md5('url|'.$name),
            'url_prefix' => 2,
            'created_at' => now(),
        ];
    }

    /**
     * 페이지 제목 항목 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function title(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->unique()->sentence(3);

            return [
                'type' => 'title',
                'name' => $name,
                'name_hash' => md5('title|'.$name),
                'url_prefix' => null,
            ];
        });
    }

    /**
     * 검색어 항목 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function search(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->unique()->word();

            return [
                'type' => 'search',
                'name' => $name,
                'name_hash' => md5('search|'.$name),
                'url_prefix' => null,
            ];
        });
    }

    /**
     * 지정한 종류와 이름으로 만드는 상태
     *
     * @param  string  $type  이름 종류
     * @param  string  $name  정규화된 이름
     * @return static 팩토리 인스턴스
     */
    public function of(string $type, string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'name' => $name,
            'name_hash' => md5($type.'|'.$name),
            'url_prefix' => $type === 'url' ? 2 : null,
        ]);
    }
}
