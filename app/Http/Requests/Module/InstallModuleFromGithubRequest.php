<?php

namespace App\Http\Requests\Module;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GitHub에서 모듈 설치 요청
 */
class InstallModuleFromGithubRequest extends FormRequest
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
            'github_url' => [
                'required',
                'url',
                'regex:/^https?:\/\/(www\.)?github\.com\/[a-zA-Z0-9\-_]+\/[a-zA-Z0-9\-_]+\/?$/',
            ],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.module.install_from_github_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'github_url.required' => __('modules.validation.github_url_required'),
            'github_url.url' => __('modules.validation.github_url_invalid'),
            'github_url.regex' => __('modules.validation.github_url_format'),
        ];
    }
}
