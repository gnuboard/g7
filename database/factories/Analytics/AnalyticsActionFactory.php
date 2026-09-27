<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\ActionType;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsAction>
 */
class AnalyticsActionFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsAction>
     */
    protected $model = AnalyticsAction::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        return [
            'visit_id' => AnalyticsVisit::factory(),
            'visitor_key' => bin2hex(random_bytes(16)),
            'action_type' => ActionType::Pageview,
            'action_name_id' => AnalyticsActionName::factory(),
            'page_title_id' => null,
            'occurred_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'time_spent_seconds' => $this->faker->numberBetween(0, 300),
            'search_result_count' => null,
            'search_tab' => null,
            'custom_dimensions' => null,
            'created_at' => now(),
        ];
    }

    /**
     * 지정한 방문에 속한 액션 상태
     *
     * 방문자 키를 방문의 값으로 맞춰 둡니다 — 두 값이 어긋나면 방문자 기준 삭제가
     * 액션을 놓칩니다.
     *
     * @param  AnalyticsVisit  $visit  대상 방문
     * @return static 팩토리 인스턴스
     */
    public function forVisit(AnalyticsVisit $visit): static
    {
        return $this->state(fn (array $attributes) => [
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
        ]);
    }

    /**
     * 사이트 내부 검색 액션 상태
     *
     * @param  int  $resultCount  검색 결과 건수
     * @return static 팩토리 인스턴스
     */
    public function siteSearch(int $resultCount = 12): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ActionType::SiteSearch,
            'search_result_count' => $resultCount,
            'search_tab' => 'all',
            'time_spent_seconds' => null,
        ]);
    }

    /**
     * 파일 내려받기 액션 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function download(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ActionType::Download,
            'time_spent_seconds' => null,
        ]);
    }

    /**
     * 외부 링크 이동 액션 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function outlink(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => ActionType::Outlink,
            'time_spent_seconds' => null,
        ]);
    }
}
