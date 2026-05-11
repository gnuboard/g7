<?php

namespace Modules\Gnuboard7\HelloModule\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Gnuboard7\HelloModule\Models\Memo;

/**
 * 메모 팩토리
 *
 * 테스트/샘플 데이터 생성에 사용됩니다.
 */
class MemoFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<Memo>
     */
    protected $model = Memo::class;

    /**
     * 모델 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'title' => $this->faker->sentence(3),
            'content' => $this->faker->paragraph(3),
        ];
    }
}
