<?php

namespace App\Http\Requests\TemplateCustomTranslation;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 커스텀 다국어 키 수정 요청 검증.
 *
 * 속성 편집 모달 번역 탭에서 로케일별 값을 일괄 편집할 때 호출됩니다.
 * 낙관적 잠금을 위해 `expected_lock_version` 이 필수이며,
 * 현재 DB 버전과 불일치 시 Service 가 409 Conflict 를 던집니다.
 *
 * 권한은 라우트 permission 미들웨어(core.templates.layouts.edit)에서 처리합니다.
 */
class UpdateCustomTranslationRequest extends FormRequest
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
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];

        return HookManager::applyFilters('core.custom_translation.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string> 메시지 맵
     */
    public function messages(): array
    {
        return [
            'values.required' => __('validation.custom_translation.values.required'),
            'values.array' => __('validation.custom_translation.values.array'),
            'expected_lock_version.required' => __('validation.custom_translation.expected_lock_version.required'),
            'expected_lock_version.integer' => __('validation.custom_translation.expected_lock_version.integer'),
            'expected_lock_version.min' => __('validation.custom_translation.expected_lock_version.min'),
        ];
    }
}
