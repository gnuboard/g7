<?php

namespace App\Http\Requests\TemplateCustomTranslation;

use App\Extension\HookManager;
use App\Models\TemplateCustomTranslation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 커스텀 다국어 키 일괄 삭제 요청 검증.
 *
 * 레이아웃 편집기 다국어 관리 모달의 "선택 삭제"/"미사용 전체 삭제" 에서
 * 호출됩니다. 권한은 라우트 permission 미들웨어(core.templates.layouts.edit)
 * 에서 처리합니다.
 */
class BulkDestroyCustomTranslationRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(TemplateCustomTranslation::class, 'id')],
        ];

        return HookManager::applyFilters('core.custom_translation.bulk_destroy_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string> 메시지 맵
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('validation.custom_translation.ids.required'),
            'ids.array' => __('validation.custom_translation.ids.array'),
            'ids.min' => __('validation.custom_translation.ids.min'),
            'ids.*.integer' => __('validation.custom_translation.ids.integer'),
            'ids.*.exists' => __('validation.custom_translation.ids.exists'),
        ];
    }
}
