<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsArchiveRun>
 */
class AnalyticsArchiveRunFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsArchiveRun>
     */
    protected $model = AnalyticsArchiveRun::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(5);

        return [
            'run_type' => ArchiveRunType::Day,
            'period_start' => now()->subDay()->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
            'trigger' => 'scheduled',
            'status' => ArchiveRunStatus::Success,
            'processed_visits' => $this->faker->numberBetween(0, 5000),
            'processed_actions' => $this->faker->numberBetween(0, 20000),
            'chunks' => $this->faker->numberBetween(1, 10),
            'progress_percent' => 100,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMinutes(2),
            'error' => null,
            'triggered_by' => null,
            'created_at' => now(),
        ];
    }

    /**
     * 실행 중인 상태
     *
     * @param  int  $progress  진행률 (%)
     * @return static 팩토리 인스턴스
     */
    public function running(int $progress = 40): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ArchiveRunStatus::Running,
            'progress_percent' => $progress,
            'finished_at' => null,
        ]);
    }

    /**
     * 실패한 상태
     *
     * @param  string  $error  실패 사유
     * @return static 팩토리 인스턴스
     */
    public function failed(string $error = '집계 중 오류가 발생했습니다.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ArchiveRunStatus::Failed,
            'error' => $error,
            'progress_percent' => 0,
        ]);
    }

    /**
     * 관리자가 수동 실행한 상태
     *
     * @param  int|null  $userId  실행한 관리자 ID
     * @return static 팩토리 인스턴스
     */
    public function manual(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => 'manual',
            'triggered_by' => $userId,
        ]);
    }

    /**
     * 관리자가 통계 화면을 열 때 돈 집계 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function onView(): static
    {
        return $this->state(fn (array $attributes) => [
            'run_type' => ArchiveRunType::Hour,
            'trigger' => 'on_view',
            'triggered_by' => null,
        ]);
    }
}
