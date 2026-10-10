<?php

namespace Database\Factories\Analytics;

use App\Models\Analytics\AnalyticsSalt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSalt>
 */
class AnalyticsSaltFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsSalt>
     */
    protected $model = AnalyticsSalt::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        return [
            'salt_date' => now()->toDateString(),
            'salt' => bin2hex(random_bytes(32)),
            'created_at' => now(),
        ];
    }

    /**
     * 지정한 일자의 솔트 상태
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return static 팩토리 인스턴스
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'salt_date' => $date,
        ]);
    }
}
