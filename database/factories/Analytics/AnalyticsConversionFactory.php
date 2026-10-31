<?php

namespace Database\Factories\Analytics;

use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsConversion>
 */
class AnalyticsConversionFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsConversion>
     */
    protected $model = AnalyticsConversion::class;

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
            'goal_key' => 'core.user_registered',
            'extension_type' => null,
            'extension_identifier' => null,
            'label' => null,
            'revenue' => null,
            'currency' => null,
            'occurred_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'custom_dimensions' => null,
            'created_at' => now(),
        ];
    }

    /**
     * 지정한 방문에 속한 전환 상태
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
     * 확장이 등록한 목표의 전환 상태
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $goal  목표 이름
     * @param  string  $type  확장 종류 (module, plugin)
     * @return static 팩토리 인스턴스
     */
    public function forExtension(string $identifier, string $goal, string $type = 'module'): static
    {
        return $this->state(fn (array $attributes) => [
            'goal_key' => $identifier.'.'.$goal,
            'extension_type' => $type,
            'extension_identifier' => $identifier,
        ]);
    }

    /**
     * 금액이 있는 전환 상태
     *
     * @param  float  $amount  전환 금액
     * @param  string  $currency  통화 코드
     * @return static 팩토리 인스턴스
     */
    public function withRevenue(float $amount = 45000, string $currency = 'KRW'): static
    {
        return $this->state(fn (array $attributes) => [
            'revenue' => $amount,
            'currency' => $currency,
        ]);
    }
}
