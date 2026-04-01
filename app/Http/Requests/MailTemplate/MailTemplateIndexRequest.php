<?php

namespace App\Http\Requests\MailTemplate;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 메일 템플릿 목록 조회 요청을 검증합니다.
 */
class MailTemplateIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'search_type' => ['nullable', 'string', Rule::in(['all', 'subject', 'body'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in(['id', 'type', 'is_active', 'created_at', 'updated_at'])],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];

        return HookManager::applyFilters('core.mail_template.index_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'per_page.min' => __('mail_template.validation.per_page_min'),
            'per_page.max' => __('mail_template.validation.per_page_max'),
        ];
    }
}
