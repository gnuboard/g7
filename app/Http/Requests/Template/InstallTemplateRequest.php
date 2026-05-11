<?php

namespace App\Http\Requests\Template;

use App\Extension\HookManager;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 템플릿 설치 요청
 */
class InstallTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행되므로
     * FormRequest 레벨은 항상 통과시킵니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'template_name' => ['required', 'string', 'max:255', new ValidExtensionIdentifier],
            // cascade 동반 설치 — install-preview 응답을 바탕으로 사용자가 선택한 항목
            'dependencies' => ['nullable', 'array'],
            'dependencies.*.type' => ['required_with:dependencies', 'string', 'in:module,plugin'],
            'dependencies.*.identifier' => ['required_with:dependencies', 'string', 'max:255'],
            'language_packs' => ['nullable', 'array'],
            'language_packs.*' => ['string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template.install_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_name.required' => __('templates.validation.name_required'),
            'template_name.string' => __('templates.validation.name_string'),
            'template_name.max' => __('templates.validation.name_max'),
        ];
    }
}
