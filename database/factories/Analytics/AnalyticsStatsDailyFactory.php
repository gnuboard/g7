<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsStatsDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsStatsDaily>
 */
class AnalyticsStatsDailyFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsStatsDaily>
     */
    protected $model = AnalyticsStatsDaily::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $visits = $this->faker->numberBetween(10, 2000);

        return [
            'date' => now()->toDateString(),
            'visitor_type' => VisitorType::Guest,
            'visits' => $visits,
            'visitors' => (int) round($visits * 0.8),
            'pageviews' => $visits * $this->faker->numberBetween(1, 4),
            'actions' => $visits * $this->faker->numberBetween(1, 5),
            'bounces' => (int) round($visits * 0.4),
            'server_only_visits' => 0,
            'new_visitors' => (int) round($visits * 0.6),
            'returning_visitors' => (int) round($visits * 0.2),
            'total_time_seconds' => $visits * $this->faker->numberBetween(10, 300),
            'is_final' => true,
            'archived_at' => now(),
        ];
    }

    /**
     * 지정한 일자·유형의 집계 상태
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  VisitorType  $type  방문자 유형
     * @return static 팩토리 인스턴스
     */
    public function forDate(string $date, VisitorType $type = VisitorType::Guest): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'visitor_type' => $type,
        ]);
    }

    /**
     * 아직 확정되지 않은 오늘치 집계 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function provisional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_final' => false,
        ]);
    }
}
