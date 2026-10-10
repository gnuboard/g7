<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsStatsHourly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsStatsHourly>
 */
class AnalyticsStatsHourlyFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsStatsHourly>
     */
    protected $model = AnalyticsStatsHourly::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $visits = $this->faker->numberBetween(1, 200);

        return [
            'hour_start' => now()->startOfHour(),
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
            'archived_at' => now(),
        ];
    }

    /**
     * 지정한 시간·유형의 집계 상태
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  VisitorType  $type  방문자 유형
     * @return static 팩토리 인스턴스
     */
    public function forHour(string $hourStart, VisitorType $type = VisitorType::Guest): static
    {
        return $this->state(fn (array $attributes) => [
            'hour_start' => $hourStart,
            'visitor_type' => $type,
        ]);
    }
}
