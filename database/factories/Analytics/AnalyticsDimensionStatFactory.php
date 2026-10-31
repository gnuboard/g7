<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsDimensionStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsDimensionStat>
 */
class AnalyticsDimensionStatFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsDimensionStat>
     */
    protected $model = AnalyticsDimensionStat::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $label = '/'.$this->faker->slug();
        $visits = $this->faker->numberBetween(1, 500);

        return [
            'date' => now()->toDateString(),
            'visitor_type' => VisitorType::Guest,
            'dimension' => StatsDimension::PageUrl->value,
            'label_hash' => md5($label),
            'label' => $label,
            'action_name_id' => null,
            'visits' => $visits,
            'hits' => $visits * $this->faker->numberBetween(1, 3),
            'entries' => (int) round($visits * 0.3),
            'exits' => (int) round($visits * 0.3),
            'bounces' => (int) round($visits * 0.2),
            'total_time_seconds' => $visits * $this->faker->numberBetween(10, 200),
            'extra' => null,
            'archived_at' => now(),
        ];
    }

    /**
     * 지정한 차원·라벨의 집계 상태
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $label  라벨 원값
     * @param  string|null  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  VisitorType  $type  방문자 유형
     * @return static 팩토리 인스턴스
     */
    public function of(string $dimension, string $label, ?string $date = null, VisitorType $type = VisitorType::Guest): static
    {
        return $this->state(fn (array $attributes) => array_filter([
            'dimension' => $dimension,
            'label' => $label,
            'label_hash' => md5($label),
            'visitor_type' => $type,
            'date' => $date,
        ], fn ($value) => $value !== null));
    }
}
