<?php

namespace App\Http\Requests\Module;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class RefreshModuleLayoutsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            'module_name' => 'required|string|max:255',
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
        ];
    }
}
