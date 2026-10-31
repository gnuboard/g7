<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\CollectFailureReason;
use App\Models\Analytics\AnalyticsCollectFailure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsCollectFailure>
 */
class AnalyticsCollectFailureFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsCollectFailure>
     */
    protected $model = AnalyticsCollectFailure::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        return [
            'reason' => CollectFailureReason::Validation,
            'message' => '요청 형식이 규칙에 맞지 않습니다.',
            'url' => '/'.$this->faker->slug(),
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'payload_excerpt' => ['t' => 'pageview'],
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }

    /**
     * 지정한 사유의 실패 상태
     *
     * @param  CollectFailureReason  $reason  실패 사유
     * @param  string|null  $message  실패 메시지
     * @return static 팩토리 인스턴스
     */
    public function because(CollectFailureReason $reason, ?string $message = null): static
    {
        return $this->state(fn (array $attributes) => array_filter([
            'reason' => $reason,
            'message' => $message,
        ], fn ($value) => $value !== null));
    }
}
