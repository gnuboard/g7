<?php

namespace App\Http\Requests\TemplateCustomTranslation;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 커스텀 다국어 키 목록 조회 요청 검증.
 *
 * 권한은 라우트 permission 미들웨어(core.templates.layouts.edit)에서 처리합니다.
 */
class IndexCustomTranslationRequest extends FormRequest
{
    /**
     * 권한은 미들웨어 체인에서 처리합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'layout_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'in:active,orphaned'],
        ];

        return HookManager::applyFilters('core.custom_translation.index_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string> 메시지 맵
     */
    public function messages(): array
    {
        return [
            'layout_name.string' => __('validation.custom_translation.layout_name.string'),
            'layout_name.max' => __('validation.custom_translation.layout_name.max', ['max' => 150]),
            'status.in' => __('validation.custom_translation.status.in'),
        ];
    }
}
