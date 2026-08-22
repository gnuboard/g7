<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class RefreshPluginLayoutsRequest extends FormRequest
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
            'plugin_name' => 'required|string|max:255',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.plugin.refresh_layouts_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plugin_name.required' => __('plugins.validation.name_required'),
            'plugin_name.string' => __('plugins.validation.name_string'),
            'plugin_name.max' => __('plugins.validation.name_max'),
        ];
    }
}
