<?php

namespace App\Http\Requests\TemplateCustomTranslation;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 커스텀 다국어 키 생성 요청 검증.
 *
 * 인라인 편집 확정 시 평문을 동적 다국어 키로 전환할 때 호출됩니다.
 * 키(`custom.{layout}.{seq}`)는 Service 가 자동 생성하므로 클라이언트는
 * 출처 레이아웃·편집 로케일·입력값만 전달합니다.
 *
 * 권한은 라우트 permission 미들웨어(core.templates.layouts.edit)에서 처리합니다.
 */
class StoreCustomTranslationRequest extends FormRequest
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
            'layout_name' => ['required', 'string', 'max:150'],
            'locale' => ['required', 'string', 'max:35'],
            'value' => ['required', 'string'],
        ];

        return HookManager::applyFilters('core.custom_translation.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string> 메시지 맵
     */
    public function messages(): array
    {
        return [
            'layout_name.required' => __('validation.custom_translation.layout_name.required'),
            'layout_name.string' => __('validation.custom_translation.layout_name.string'),
            'layout_name.max' => __('validation.custom_translation.layout_name.max', ['max' => 150]),
            'locale.required' => __('validation.custom_translation.locale.required'),
            'locale.string' => __('validation.custom_translation.locale.string'),
            'value.required' => __('validation.custom_translation.value.required'),
            'value.string' => __('validation.custom_translation.value.string'),
        ];
    }
}
