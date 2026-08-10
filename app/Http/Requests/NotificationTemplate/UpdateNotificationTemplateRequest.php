<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTemplateRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // subject 컬럼은 nullable 이다 (제목 개념이 없는 채널이 존재).
            // 스키마와 정합하도록 미전송/null 을 허용한다.
            'subject' => ['sometimes', 'nullable', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
            'click_url' => ['nullable', 'string', 'max:500'],
            'recipients' => ['sometimes', 'nullable', 'array'],
            'recipients.*.type' => ['required', 'string', 'in:trigger_user,related_user,role,specific_users'],
            'recipients.*.value' => ['nullable'],
            'recipients.*.relation' => ['nullable', 'string', 'max:100'],
            'recipients.*.exclude_trigger_user' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.notification_template.update_validation_rules',
            $rules,
            $this->route('template')
        );
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array 규칙별 오류 메시지
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('validation.required', ['attribute' => __('notification.subject')]),
            'body.required' => __('validation.required', ['attribute' => __('notification.body')]),
        ];
    }
}
