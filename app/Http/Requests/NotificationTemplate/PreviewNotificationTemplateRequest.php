<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Models\NotificationDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewNotificationTemplateRequest extends FormRequest
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
        return [
            'definition_id' => ['required', 'integer', Rule::exists(NotificationDefinition::class, 'id')],
            'subject' => ['required', 'array'],
            'body' => ['required', 'array'],
            'locale' => ['sometimes', 'string', 'max:10'],
        ];
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'definition_id.required' => __('validation.required', ['attribute' => __('notification.definition')]),
            'definition_id.exists' => __('validation.exists', ['attribute' => __('notification.definition')]),
            'subject.required' => __('validation.required', ['attribute' => __('notification.subject')]),
            'body.required' => __('validation.required', ['attribute' => __('notification.body')]),
        ];
    }
}
