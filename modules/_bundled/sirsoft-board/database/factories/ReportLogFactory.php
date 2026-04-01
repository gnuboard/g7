<?php

namespace Modules\Sirsoft\Board\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Models\ReportLog;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Sirsoft\Board\Models\ReportLog>
 */
class ReportLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = ReportLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => null, // 테스트에서 오버라이드 필수
            'reporter_id' => User::factory(),
            'snapshot' => [
                'board_name' => fake()->word(),
                'title' => fake()->sentence(),
                'content' => fake()->paragraph(),
                'content_mode' => 'text',
                'author_name' => fake()->name(),
            ],
            'reason_type' => fake()->randomElement(ReportReasonType::cases())->value,
            'reason_detail' => fake()->sentence(),
            'metadata' => [
                'ip_address' => fake()->ipv4(),
                'user_agent' => fake()->userAgent(),
            ],
        ];
    }
}