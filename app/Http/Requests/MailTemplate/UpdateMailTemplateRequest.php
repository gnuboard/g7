<?php

namespace App\Http\Requests\MailTemplate;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 메일 템플릿 수정 요청을 검증합니다.
 */
class UpdateMailTemplateRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (권한은 permission 미들웨어에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'subject' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters('core.mail_template.update_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('mail_template.validation.subject_required'),
            'body.required' => __('mail_template.validation.body_required'),
        ];
    }
}
