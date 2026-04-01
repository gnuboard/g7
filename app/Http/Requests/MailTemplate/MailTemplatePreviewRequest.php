<?php

namespace App\Http\Requests\MailTemplate;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 메일 템플릿 미리보기 요청을 검증합니다.
 */
class MailTemplatePreviewRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65535'],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string', 'max:100'],
        ];

        return HookManager::applyFilters('core.mail_template.preview_validation_rules', $rules, $this);
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
