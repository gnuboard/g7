<?php

namespace Database\Factories;

use App\Models\MailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MailTemplate 팩토리
 *
 * @extends Factory<MailTemplate>
 */
class MailTemplateFactory extends Factory
{
    /**
     * @var string 팩토리 모델 클래스
     */
    protected $model = MailTemplate::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->unique()->slug(2),
            'subject' => [
                'ko' => '[테스트] '.$this->faker->sentence(),
                'en' => '[Test] '.$this->faker->sentence(),
            ],
            'body' => [
                'ko' => '<p>'.$this->faker->paragraph().'</p>',
                'en' => '<p>'.$this->faker->paragraph().'</p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
            ],
            'is_active' => true,
            'is_default' => true,
            'updated_by' => null,
        ];
    }

    /**
     * 비활성 상태
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * 특정 유형
     *
     * @param string $type 템플릿 유형
     * @return static
     */
    public function withType(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    /**
     * 변수 포함 본문
     *
     * @return static
     */
    public function withVariables(): static
    {
        return $this->state(fn () => [
            'subject' => [
                'ko' => '[{app_name}] {name}님 환영합니다',
                'en' => '[{app_name}] Welcome {name}',
            ],
            'body' => [
                'ko' => '<p>{name}님, {app_name}에 가입해 주셔서 감사합니다.</p>',
                'en' => '<p>Welcome {name}, thank you for joining {app_name}.</p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
            ],
        ]);
    }
}
