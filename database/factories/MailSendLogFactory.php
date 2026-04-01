<?php

namespace Database\Factories;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 메일 발송 이력 팩토리
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MailSendLog>
 */
class MailSendLogFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var string
     */
    protected $model = MailSendLog::class;

    /**
     * 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_email' => $this->faker->safeEmail(),
            'sender_name' => $this->faker->name(),
            'recipient_email' => $this->faker->safeEmail(),
            'recipient_name' => $this->faker->name(),
            'subject' => $this->faker->sentence(),
            'body' => '<p>'.$this->faker->paragraph().'</p>',
            'template_type' => $this->faker->randomElement(['welcome', 'password_reset', 'new_comment']),
            'extension_type' => ExtensionOwnerType::Core->value,
            'extension_identifier' => 'core',
            'source' => $this->faker->randomElement(['notification', 'test_mail', null]),
            'status' => MailSendStatus::Sent->value,
            'error_message' => null,
            'sent_at' => now(),
        ];
    }

    /**
     * 발송 실패 상태
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MailSendStatus::Failed->value,
            'error_message' => $this->faker->sentence(),
        ]);
    }

    /**
     * 특정 확장 지정
     *
     * @param ExtensionOwnerType $type 확장 타입
     * @param string $identifier 확장 식별자
     * @return static
     */
    public function forExtension(ExtensionOwnerType $type, string $identifier): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => $type->value,
            'extension_identifier' => $identifier,
        ]);
    }

    /**
     * 특정 템플릿 유형 지정
     */
    public function withTemplateType(string $templateType): static
    {
        return $this->state(fn (array $attributes) => [
            'template_type' => $templateType,
        ]);
    }
}
