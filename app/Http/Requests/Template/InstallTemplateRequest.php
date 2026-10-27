<?php

namespace App\Http\Requests\Template;

use App\Extension\HookManager;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'template_name' => ['required', 'string', 'max:255', new ValidExtensionIdentifier],
            // cascade 동반 설치 — install-preview 응답을 바탕으로 사용자가 선택한 항목
            'dependencies' => ['nullable', 'array'],
            'dependencies.*.type' => ['required_with:dependencies', 'string', 'in:module,plugin'],
            'dependencies.*.identifier' => ['required_with:dependencies', 'string', 'max:255'],
            // 함께 설치할 선택 연동 확장(7.1.4) — 설치 창 「함께 쓰면 연동되는 확장(선택)」에서 고른 항목. 템플릿은 고를 수 없다
            'integrations' => ['nullable', 'array'],
            'integrations.*.type' => ['required_with:integrations', 'string', 'in:module,plugin'],
            'integrations.*.identifier' => ['required_with:integrations', 'string', 'max:255', new ValidExtensionIdentifier],
            'language_packs' => ['nullable', 'array'],
            'language_packs.*' => ['string', 'max:255'],
            // 레이아웃 설치 소스(auto|json) — 이 요청 동안만 설정을 덮는다. 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따른다.
            'layout_source' => ['nullable', 'string', 'in:auto,json'],
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
            'layout_source.in' => __('templates.errors.invalid_layout_source'),
        ];
    }

    /**
     * 검증 오류 문구에 쓸 필드 이름을 반환합니다 — 동반 설치 항목(의존·선택 연동·언어팩)은 인덱스마다 같은 이름을 쓴다.
     *
     * @return array<string, string> 필드 키(와일드카드 포함) => 표시 이름
     */
    public function attributes(): array
    {
        return [
            'dependencies' => __('extensions.install_fields.dependencies'),
            'dependencies.*.type' => __('extensions.install_fields.dependency_type'),
            'dependencies.*.identifier' => __('extensions.install_fields.dependency_identifier'),
            'integrations' => __('extensions.install_fields.integrations'),
            'integrations.*.type' => __('extensions.install_fields.integration_type'),
            'integrations.*.identifier' => __('extensions.install_fields.integration_identifier'),
            'language_packs' => __('extensions.install_fields.language_packs'),
            'language_packs.*' => __('extensions.install_fields.language_pack'),
        ];
    }
}
