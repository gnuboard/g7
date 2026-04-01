<?php

namespace Modules\Sirsoft\Board\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Report;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Sirsoft\Board\Models\Report>
 */
class ReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = Report::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $targetIdCounter = 0;

        return [
            'board_id' => 1, // 테스트에서 오버라이드 필수
            'target_type' => fake()->randomElement(['post', 'comment']),
            'target_id' => ++$targetIdCounter,
            'author_id' => User::factory(),
            'status' => ReportStatus::Pending,
            'last_reported_at' => now(),
            'last_activated_at' => null,
            'processed_by' => null,
            'processed_at' => null,
            'process_histories' => null,
            'metadata' => [
                'ip_address' => fake()->ipv4(),
                'user_agent' => fake()->userAgent(),
            ],
        ];
    }

    /**
     * pending 상태로 설정
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Pending,
            'processed_by' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * review 상태로 설정
     */
    public function review(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Review,
        ]);
    }

    /**
     * rejected 상태로 설정
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Rejected,
            'processed_by' => 1,
            'processed_at' => now(),
        ]);
    }

    /**
     * suspended 상태로 설정
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Suspended,
            'processed_by' => 1,
            'processed_at' => now(),
        ]);
    }

    /**
     * post 타입으로 설정
     */
    public function post(): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => 'post',
        ]);
    }

    /**
     * comment 타입으로 설정
     */
    public function comment(): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => 'comment',
        ]);
    }
}