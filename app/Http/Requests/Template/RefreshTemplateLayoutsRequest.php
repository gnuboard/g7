<?php

namespace App\Http\Requests\Template;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class RefreshTemplateLayoutsRequest extends FormRequest
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
            'template_name' => 'required|string|max:255',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template.refresh_layouts_validation_rules', $rules, $this);
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
