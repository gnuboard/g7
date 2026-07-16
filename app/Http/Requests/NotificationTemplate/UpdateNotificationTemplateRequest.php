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
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'subject' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
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
            'core.notification_template.filter_update_rules',
            $rules,
            $this->route('template')
        );
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('validation.required', ['attribute' => __('notification.subject')]),
            'body.required' => __('validation.required', ['attribute' => __('notification.body')]),
        ];
    }
}
