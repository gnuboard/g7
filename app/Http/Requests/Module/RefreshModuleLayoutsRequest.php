<?php

namespace App\Http\Requests\Module;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RefreshModuleLayoutsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 라우트의 permission 미들웨어에서 검증)
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
            'module_name' => 'required|string|max:255',
            // 레이아웃 설치 소스(auto|json) — 이 요청 동안만 설정을 덮는다. 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따른다.
            'layout_source' => ['nullable', 'string', 'in:auto,json'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.module.refresh_layouts_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'module_name.required' => __('modules.validation.name_required'),
            'module_name.string' => __('modules.validation.name_string'),
            'module_name.max' => __('modules.validation.name_max'),
            'layout_source.in' => __('modules.errors.invalid_layout_source'),
        ];
    }
}
