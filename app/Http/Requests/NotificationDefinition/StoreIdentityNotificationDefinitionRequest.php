<?php

namespace App\Http\Requests\NotificationDefinition;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Enums\NotificationDefinitionCategory;
use App\Extension\HookManager;
use App\Notifications\Identity\IdentityNotificationType;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 본인인증 정책별 문구(알림 정의) 추가 요청 검증.
 *
 * 알림 설정 > 분류 「본인인증」 > [정책별 문구 추가] 가 보낸다. 옛 IDV 메시지 정의 추가(7.0.x)의
 * 규칙을 그대로 옮겼다:
 * - 운영자가 추가한 본인인증 정책(source_type=admin)의 키만 고를 수 있다 — 코어·확장 정책의 문구는
 *   목적별 정의가 담당한다.
 * - 정책마다 정의는 하나다(type `identity.policy.{key}` 유일).
 * 정의의 소유(core/admin)·type·훅 접두사·채널은 서버가 정한다 — 요청으로 받지 않는다.
 * 권한은 라우트 permission 미들웨어(core.settings.update)가 담당한다.
 */
class StoreIdentityNotificationDefinitionRequest extends FormRequest
{
    /**
     * 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'category' => ['required', 'string', Rule::in([NotificationDefinitionCategory::Identity->value])],
            'policy_key' => [
                'required',
                'string',
                'max:120',
                $this->adminPolicyKeyRule(),
                $this->uniquePolicyDefinitionRule(),
            ],
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 1000)],
            'templates' => ['required', 'array'],
            'templates.mail' => ['required', 'array'],
            'templates.mail.subject' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'templates.mail.body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
        ];

        $rules = HookManager::applyFilters(
            'core.notification_definition.store_validation_rules',
            $rules,
            $this
        );

        // 7.0.x 본인인증 메시지 정의 추가 검증 필터 — 옛 이름으로도 거른다(@deprecated 7.1.0, 8.0 제거).
        return HookManager::applyFilters('core.identity.message_definition.store_validation_rules', $rules);
    }

    /**
     * 검증 항목 표시명을 반환합니다.
     *
     * @return array<string, string> 필드 → 표시명
     */
    public function attributes(): array
    {
        return [
            'category' => __('validation.identity_notification.attributes.category'),
            'policy_key' => __('validation.identity_notification.attributes.policy_key'),
            'name' => __('validation.identity_notification.attributes.name'),
            'description' => __('validation.identity_notification.attributes.description'),
            'templates' => __('validation.identity_notification.attributes.templates'),
            'templates.mail' => __('validation.identity_notification.attributes.templates'),
            'templates.mail.subject' => __('validation.identity_notification.attributes.subject'),
            'templates.mail.body' => __('validation.identity_notification.attributes.body'),
        ];
    }

    /**
     * 정책 키가 운영자가 추가한 본인인증 정책인지 확인하는 룰.
     *
     * @return Closure 검증 클로저
     */
    protected function adminPolicyKeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $exists = app(IdentityPolicyRepositoryInterface::class)->existsByKeyAndSourceType($value, 'admin');

            if (! $exists) {
                $fail(__('validation.identity_notification.policy_key_not_admin_policy'));
            }
        };
    }

    /**
     * 같은 정책의 본인인증 정의가 이미 있는지 확인하는 룰.
     *
     * @return Closure 검증 클로저
     */
    protected function uniquePolicyDefinitionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $existing = app(NotificationDefinitionRepositoryInterface::class)
                ->findByType(IdentityNotificationType::forPolicy($value));

            if ($existing !== null) {
                $fail(__('validation.identity_notification.definition_already_exists'));
            }
        };
    }
}
